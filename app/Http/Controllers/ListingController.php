<?php

namespace App\Http\Controllers;

use App\Enums\ListingStatus;
use App\Http\Resources\ListingResource;
use App\Http\Resources\TradeResource;
use App\Models\InventoryItem;
use App\Models\Listing;
use App\Services\Trading\TradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    public function __construct(private readonly TradeService $trades) {}

    /**
     * The marketplace: active listings from every seller.
     */
    public function index(Request $request): JsonResponse
    {
        $listings = Listing::with(['itemDescription', 'seller'])
            ->where('status', ListingStatus::Active)
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['listings' => ListingResource::collection($listings)]);
    }

    /**
     * The authenticated seller's own listings.
     */
    public function mine(Request $request): JsonResponse
    {
        $listings = Listing::with(['itemDescription', 'seller'])
            ->where('seller_id', $request->user()->id)
            ->latest('id')
            ->get();

        return response()->json(['listings' => ListingResource::collection($listings)]);
    }

    /**
     * List one of the seller's tradable items for sale.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'price' => ['required', 'integer', 'min:1'],
        ]);

        $seller = $request->user();
        $item = InventoryItem::with('itemDescription')
            ->where('id', $validated['inventory_item_id'])
            ->where('user_id', $seller->id)
            ->first();

        if ($item === null) {
            return response()->json(['message' => 'That item is not in your inventory.', 'code' => 'not_your_item'], 422);
        }

        if (! $item->tradable) {
            return response()->json(['message' => 'This item is trade-locked and cannot be listed yet.', 'code' => 'item_locked'], 422);
        }

        $alreadyListed = Listing::where('seller_id', $seller->id)
            ->where('asset_id', $item->asset_id)
            ->where('status', ListingStatus::Active)
            ->exists();

        if ($alreadyListed) {
            return response()->json(['message' => 'This item is already listed.', 'code' => 'already_listed'], 422);
        }

        $listing = Listing::create([
            'seller_id' => $seller->id,
            'inventory_item_id' => $item->id,
            'item_description_id' => $item->item_description_id,
            'app_id' => $item->app_id,
            'context_id' => $item->context_id,
            'market_hash_name' => $item->itemDescription->market_hash_name,
            'asset_id' => $item->asset_id,
            'price' => $validated['price'],
            'currency' => config('trades.currency'),
            'status' => ListingStatus::Active,
        ]);

        $listing->load(['itemDescription', 'seller']);

        return response()->json(['listing' => new ListingResource($listing)], 201);
    }

    /**
     * The seller withdraws their own active listing.
     */
    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        if ($listing->seller_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($listing->status === ListingStatus::Active) {
            $listing->update(['status' => ListingStatus::Cancelled]);
        }

        return response()->json(['message' => 'Listing cancelled.']);
    }

    /**
     * Buy a listing: hold the buyer's funds and open the P2P trade.
     */
    public function purchase(Request $request, Listing $listing): JsonResponse
    {
        $buyer = $request->user();

        if ($listing->status !== ListingStatus::Active) {
            return response()->json(['message' => 'This listing is no longer available.', 'code' => 'unavailable'], 409);
        }

        if ($listing->seller_id === $buyer->id) {
            return response()->json(['message' => 'You cannot buy your own listing.', 'code' => 'own_listing'], 422);
        }

        if ($listing->seller->isSuspended()) {
            return response()->json(['message' => 'The seller is suspended.', 'code' => 'seller_suspended'], 422);
        }

        if (! $buyer->trade_url) {
            return response()->json(['message' => 'Add your Steam trade URL before buying.', 'code' => 'trade_url_required'], 422);
        }

        $item = $listing->inventoryItem;

        if ($item === null) {
            return response()->json(['message' => 'The seller no longer holds this item.', 'code' => 'item_gone'], 422);
        }

        if ($buyer->ensureWallet()->balance < $listing->price) {
            return response()->json(['message' => 'Your balance is too low for this purchase.', 'code' => 'insufficient_funds'], 422);
        }

        $trade = $this->trades->open($buyer, $item, $listing->price);
        $listing->update(['status' => ListingStatus::Sold]);

        $trade->load(['seller', 'buyer', 'itemDescription', 'events']);

        return response()->json(['trade' => new TradeResource($trade)], 201);
    }
}
