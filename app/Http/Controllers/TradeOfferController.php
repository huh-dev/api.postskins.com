<?php

namespace App\Http\Controllers;

use App\Enums\OfferItemSide;
use App\Enums\TradeOfferStatus;
use App\Enums\TradePostStatus;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\TradeExecutionException;
use App\Http\Resources\TradeOfferResource;
use App\Http\Resources\TradeResource;
use App\Jobs\SendTradeOffer;
use App\Models\InventoryItem;
use App\Models\TradeOffer;
use App\Models\TradePost;
use App\Services\Trading\TradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TradeOfferController extends Controller
{
    public function __construct(private readonly TradeService $trades) {}

    /**
     * The owner's inbox of pending offers on one of their posts.
     */
    public function index(Request $request, TradePost $post): JsonResponse
    {
        if ($post->owner_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $offers = $post->offers()
            ->with(['offerer', 'items.itemDescription'])
            ->where('status', TradeOfferStatus::Pending)
            ->latest('id')
            ->get();

        return response()->json(['offers' => TradeOfferResource::collection($offers)]);
    }

    /**
     * The caller's outgoing offers across every post they have made an offer on,
     * newest first and in any status, so they can track each offer's outcome.
     */
    public function sent(Request $request): JsonResponse
    {
        $offers = TradeOffer::query()
            ->where('offerer_id', $request->user()->id)
            ->with(['offerer', 'post.owner', 'items.itemDescription'])
            ->latest('id')
            ->get();

        return response()->json(['offers' => TradeOfferResource::collection($offers)]);
    }

    /**
     * The caller's consolidated inbox of pending offers across all of their
     * posts, so they can review every actionable offer in one place.
     */
    public function received(Request $request): JsonResponse
    {
        $offers = TradeOffer::query()
            ->where('status', TradeOfferStatus::Pending)
            ->whereHas('post', fn ($query) => $query->where('owner_id', $request->user()->id))
            ->with(['offerer', 'post.owner', 'items.itemDescription'])
            ->latest('id')
            ->get();

        return response()->json(['offers' => TradeOfferResource::collection($offers)]);
    }

    /**
     * Submit a counter-offer against a post: hand over items and/or cash in
     * exchange for the owner's offered items.
     */
    public function store(Request $request, TradePost $post): JsonResponse
    {
        $offerer = $request->user();

        if ($post->status !== TradePostStatus::Open) {
            return response()->json(['message' => 'This post is no longer open.', 'code' => 'post_unavailable'], 409);
        }

        if ($post->owner_id === $offerer->id) {
            return response()->json(['message' => 'You cannot make an offer on your own post.', 'code' => 'own_post'], 422);
        }

        if (! $offerer->trade_url) {
            return response()->json(['message' => 'Add your Steam trade URL before making an offer.', 'code' => 'trade_url_required'], 422);
        }

        $validated = $request->validate([
            'items' => ['array'],
            'items.*.inventory_item_id' => ['required', 'integer'],
            'want_post_item_ids' => ['array'],
            'want_post_item_ids.*' => ['integer'],
            'cash_amount' => ['nullable', 'integer', 'min:0'],
            'cash_payer' => ['nullable', 'in:offerer,poster'],
            'message' => ['nullable', 'string', 'max:280'],
        ]);

        $offerItems = $validated['items'] ?? [];
        $cashAmount = $validated['cash_amount'] ?? 0;
        $cashPayer = $cashAmount > 0 ? ($validated['cash_payer'] ?? null) : null;

        if ($cashAmount > 0 && $cashPayer === null) {
            throw ValidationException::withMessages(['cash_payer' => 'Say who pays the cash.']);
        }

        // The offerer must contribute value: items, or cash they themselves pay.
        if ($offerItems === [] && ! ($cashAmount > 0 && $cashPayer === TradeOffer::PAYER_OFFERER)) {
            throw ValidationException::withMessages(['items' => 'Offer at least one item or some cash.']);
        }

        $items = InventoryItem::with('itemDescription')
            ->whereKey(array_column($offerItems, 'inventory_item_id'))
            ->where('user_id', $offerer->id)
            ->get();

        if ($items->count() !== count($offerItems)) {
            throw ValidationException::withMessages(['items' => 'Some items are not in your inventory.']);
        }

        if ($items->contains(fn (InventoryItem $item): bool => ! $item->tradable)) {
            throw ValidationException::withMessages(['items' => 'One or more items are trade-locked.']);
        }

        // Which of the post's offered assets the offerer wants (default: all).
        $postOffering = $post->offeringItems()->get();
        if (! empty($validated['want_post_item_ids'])) {
            $postOffering = $postOffering->whereIn('id', $validated['want_post_item_ids'])->values();
        }

        $offer = DB::transaction(function () use ($post, $offerer, $cashAmount, $cashPayer, $validated, $items, $postOffering): TradeOffer {
            $offer = $post->offers()->create([
                'offerer_id' => $offerer->id,
                'cash_amount' => $cashAmount,
                'cash_payer' => $cashPayer,
                'currency' => $post->currency,
                'message' => $validated['message'] ?? null,
                'status' => TradeOfferStatus::Pending,
            ]);

            foreach ($items as $item) {
                $offer->items()->create([
                    'side' => OfferItemSide::FromOfferer,
                    'item_description_id' => $item->item_description_id,
                    'inventory_item_id' => $item->id,
                    'app_id' => $item->app_id,
                    'context_id' => $item->context_id,
                    'market_hash_name' => $item->itemDescription->market_hash_name,
                    'asset_id' => $item->asset_id,
                ]);
            }

            foreach ($postOffering as $postItem) {
                $offer->items()->create([
                    'side' => OfferItemSide::FromPoster,
                    'item_description_id' => $postItem->item_description_id,
                    'inventory_item_id' => $postItem->inventory_item_id,
                    'app_id' => $postItem->app_id,
                    'context_id' => $postItem->context_id,
                    'market_hash_name' => $postItem->market_hash_name,
                    'asset_id' => $postItem->asset_id,
                ]);
            }

            return $offer;
        });

        $offer->load(['offerer', 'items.itemDescription']);

        return response()->json(['offer' => new TradeOfferResource($offer)], 201);
    }

    /**
     * The offerer withdraws their own pending offer.
     */
    public function destroy(Request $request, TradeOffer $offer): JsonResponse
    {
        if ($offer->offerer_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($offer->status === TradeOfferStatus::Pending) {
            $offer->update(['status' => TradeOfferStatus::Withdrawn]);
        }

        return response()->json(['message' => 'Offer withdrawn.']);
    }

    /**
     * The post owner accepts an offer, executing the trade atomically.
     */
    public function accept(Request $request, TradeOffer $offer): JsonResponse
    {
        if ($offer->post->owner_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        try {
            $trade = $this->trades->execute($offer);
        } catch (TradeExecutionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason] + $e->context, $e->status);
        } catch (InsufficientFundsException) {
            return response()->json([
                'message' => 'The paying party has too low a balance for this trade.',
                'code' => 'insufficient_funds',
            ], 422);
        }

        // Send one atomic Steam offer carrying both parties' items.
        SendTradeOffer::dispatch($trade->id);

        $trade->load(['initiator', 'counterparty', 'items.itemDescription', 'events']);

        return response()->json(['trade' => new TradeResource($trade)], 201);
    }
}
