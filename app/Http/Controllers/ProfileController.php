<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Save the buyer's Steam trade offer URL (partner + token), which sellers
     * use to direct an item to them.
     */
    public function updateTradeUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trade_url' => [
                'required',
                'string',
                'regex:#^https://steamcommunity\.com/tradeoffer/new/\?partner=\d+&token=[\w-]+$#',
            ],
        ], [
            'trade_url.regex' => 'That does not look like a Steam trade URL. Copy it from Steam → Inventory → Trade Offers → Who can send me Trade Offers.',
        ]);

        $request->user()->update(['trade_url' => $validated['trade_url']]);

        return response()->json(['trade_url' => $validated['trade_url']]);
    }
}
