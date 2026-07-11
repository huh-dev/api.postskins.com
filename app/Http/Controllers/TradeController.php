<?php

namespace App\Http\Controllers;

use App\Http\Resources\TradeResource;
use App\Models\Trade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradeController extends Controller
{
    /**
     * The authenticated user's trades, as either party, most recent first.
     */
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $trades = Trade::query()
            ->with(['initiator', 'counterparty', 'items.itemDescription'])
            ->where(fn ($q) => $q->where('initiator_id', $userId)->orWhere('counterparty_id', $userId))
            ->latest('id')
            ->get();

        return response()->json(['trades' => TradeResource::collection($trades)]);
    }

    /**
     * Show a trade the authenticated user is a party to.
     */
    public function show(Request $request, Trade $trade): JsonResponse
    {
        if (! in_array($request->user()->id, [$trade->initiator_id, $trade->counterparty_id], true)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $trade->load(['initiator', 'counterparty', 'items.itemDescription', 'events']);

        return response()->json(['trade' => new TradeResource($trade)]);
    }
}
