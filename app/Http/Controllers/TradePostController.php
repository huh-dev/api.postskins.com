<?php

namespace App\Http\Controllers;

use App\Enums\PostItemSide;
use App\Enums\TradeOfferStatus;
use App\Enums\TradePostStatus;
use App\Http\Resources\TradePostResource;
use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\TradePost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TradePostController extends Controller
{
    /**
     * The market feed: open posts from every user, filtered and searchable.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:newest,offer_cash,want_cash'],
            'min_cash' => ['nullable', 'integer', 'min:0'],
            'max_cash' => ['nullable', 'integer', 'min:0'],
            'has_cash' => ['nullable', 'boolean'],
            'app_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $query = TradePost::query()
            ->with(['owner', 'items.itemDescription'])
            ->withCount('offers')
            ->where('status', TradePostStatus::Open);

        if (! empty($filters['app_id'])) {
            $query->where('app_id', $filters['app_id']);
        }

        if (isset($filters['min_cash'])) {
            $query->where('want_cash', '>=', $filters['min_cash']);
        }

        if (isset($filters['max_cash'])) {
            $query->where('want_cash', '<=', $filters['max_cash']);
        }

        if (! empty($filters['has_cash'])) {
            $query->where(fn ($q) => $q->where('offer_cash', '>', 0)->orWhere('want_cash', '>', 0));
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->whereHas('items', fn ($q) => $q->where('market_hash_name', 'like', $term));
        }

        match ($filters['sort'] ?? 'newest') {
            'offer_cash' => $query->orderByDesc('offer_cash'),
            'want_cash' => $query->orderByDesc('want_cash'),
            default => $query->latest('id'),
        };

        $posts = $query->paginate($filters['per_page'] ?? 24);

        return response()->json([
            'posts' => TradePostResource::collection($posts->items()),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * The authenticated user's own posts, with offer counts.
     */
    public function mine(Request $request): JsonResponse
    {
        $posts = TradePost::query()
            ->with(['owner', 'items.itemDescription'])
            ->withCount('offers')
            ->where('owner_id', $request->user()->id)
            ->latest('id')
            ->get();

        return response()->json(['posts' => TradePostResource::collection($posts)]);
    }

    /**
     * Create a trade post. The owner is always the party that will send the
     * Steam offer, so they must have a connected Steam session.
     */
    public function store(Request $request): JsonResponse
    {
        $owner = $request->user();

        if (! $owner->isSellingConnected()) {
            return response()->json([
                'message' => 'Connect your Steam account before creating a trade post.',
                'code' => 'connect_steam_required',
            ], 422);
        }

        $validated = $request->validate([
            'offering' => ['array'],
            'offering.*.inventory_item_id' => ['required', 'integer'],
            'wanting' => ['array'],
            'wanting.*.item_description_id' => ['required', 'integer', 'exists:item_descriptions,id'],
            'wanting.*.quantity' => ['nullable', 'integer', 'min:1'],
            'offer_cash' => ['nullable', 'integer', 'min:0'],
            'want_cash' => ['nullable', 'integer', 'min:0'],
            'wants_anything' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:280'],
            'app_id' => ['nullable', 'integer'],
            'context_id' => ['nullable', 'integer'],
        ]);

        $offering = $validated['offering'] ?? [];
        $wanting = $validated['wanting'] ?? [];
        $offerCash = $validated['offer_cash'] ?? 0;
        $wantCash = $validated['want_cash'] ?? 0;
        $wantsAnything = $validated['wants_anything'] ?? false;

        if ($offering === [] && $offerCash === 0) {
            throw ValidationException::withMessages(['offering' => 'Offer at least one item or some cash.']);
        }

        if ($wanting === [] && ! $wantsAnything && $wantCash === 0) {
            throw ValidationException::withMessages(['wanting' => 'Say what you want: an item, cash, or open to offers.']);
        }

        $appId = $validated['app_id'] ?? 730;
        $contextId = $validated['context_id'] ?? 2;

        $items = InventoryItem::with('itemDescription')
            ->whereKey(array_column($offering, 'inventory_item_id'))
            ->where('user_id', $owner->id)
            ->get();

        if ($items->count() !== count($offering)) {
            throw ValidationException::withMessages(['offering' => 'Some items are not in your inventory.']);
        }

        if ($items->contains(fn (InventoryItem $item): bool => ! $item->tradable)) {
            throw ValidationException::withMessages(['offering' => 'One or more items are trade-locked.']);
        }

        $descriptions = ItemDescription::query()
            ->whereKey(array_column($wanting, 'item_description_id'))
            ->get()
            ->keyBy('id');

        $post = DB::transaction(function () use ($owner, $appId, $contextId, $offerCash, $wantCash, $wantsAnything, $validated, $items, $wanting, $descriptions): TradePost {
            $post = TradePost::create([
                'owner_id' => $owner->id,
                'app_id' => $appId,
                'context_id' => $contextId,
                'offer_cash' => $offerCash,
                'want_cash' => $wantCash,
                'currency' => config('trades.currency'),
                'wants_anything' => $wantsAnything,
                'note' => $validated['note'] ?? null,
                'status' => TradePostStatus::Open,
            ]);

            foreach ($items as $item) {
                $post->items()->create([
                    'side' => PostItemSide::Offering,
                    'item_description_id' => $item->item_description_id,
                    'inventory_item_id' => $item->id,
                    'app_id' => $item->app_id,
                    'context_id' => $item->context_id,
                    'market_hash_name' => $item->itemDescription->market_hash_name,
                    'asset_id' => $item->asset_id,
                ]);
            }

            foreach ($wanting as $wish) {
                $description = $descriptions[$wish['item_description_id']];
                $post->items()->create([
                    'side' => PostItemSide::Wanting,
                    'item_description_id' => $description->id,
                    'app_id' => $appId,
                    'context_id' => $contextId,
                    'market_hash_name' => $description->market_hash_name,
                    'quantity' => $wish['quantity'] ?? 1,
                ]);
            }

            return $post;
        });

        return $this->show($request, $post->fresh(), 201);
    }

    /**
     * Show a post. The owner additionally sees the pending-offer inbox.
     */
    public function show(Request $request, TradePost $post, int $status = 200): JsonResponse
    {
        $post->load(['owner', 'items.itemDescription']);

        if ($request->user()?->id === $post->owner_id) {
            $post->load([
                'offers' => fn ($q) => $q->where('status', TradeOfferStatus::Pending)->latest('id'),
                'offers.offerer',
                'offers.items.itemDescription',
            ]);
        }

        return response()->json(['post' => new TradePostResource($post)], $status);
    }

    /**
     * The owner withdraws their own open post.
     */
    public function destroy(Request $request, TradePost $post): JsonResponse
    {
        if ($post->owner_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($post->status === TradePostStatus::Open) {
            DB::transaction(function () use ($post): void {
                $post->update(['status' => TradePostStatus::Cancelled]);
                $post->offers()->where('status', TradeOfferStatus::Pending)
                    ->update(['status' => TradeOfferStatus::Expired]);
            });
        }

        return response()->json(['message' => 'Post cancelled.']);
    }
}
