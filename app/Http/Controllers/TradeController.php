<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Http\Resources\TradeResource;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Services\Trading\TradeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradeController extends Controller
{
    public function __construct(private readonly TradeService $trades) {}

    /**
     * Buy a listed item: create the trade and hold the buyer's payment.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'price' => ['required', 'integer', 'min:1'],
        ]);

        $buyer = $request->user();
        $item = InventoryItem::with(['itemDescription', 'user'])->findOrFail($validated['inventory_item_id']);
        $seller = $item->user;

        if ($seller->id === $buyer->id) {
            return response()->json(['message' => 'You cannot buy your own item.', 'code' => 'own_item'], 422);
        }

        if ($seller->isSuspended()) {
            return response()->json(['message' => 'The seller is suspended.', 'code' => 'seller_suspended'], 422);
        }

        if (! $buyer->trade_url) {
            return response()->json([
                'message' => 'Add your Steam trade URL before buying.',
                'code' => 'trade_url_required',
            ], 422);
        }

        try {
            $trade = $this->trades->open($buyer, $item, $validated['price']);
        } catch (InsufficientFundsException) {
            return response()->json([
                'message' => 'Your balance is too low for this purchase.',
                'code' => 'insufficient_funds',
            ], 422);
        }

        return $this->tradeResponse($trade, 201);
    }

    /**
     * Show a trade the authenticated user is a party to.
     */
    public function show(Request $request, Trade $trade): JsonResponse
    {
        if (! in_array($request->user()->id, [$trade->buyer_id, $trade->seller_id], true)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $this->tradeResponse($trade);
    }

    private function tradeResponse(Trade $trade, int $status = 200): JsonResponse
    {
        $trade->load(['seller', 'buyer', 'itemDescription', 'events']);

        return response()->json(['trade' => new TradeResource($trade)], $status);
    }
}
