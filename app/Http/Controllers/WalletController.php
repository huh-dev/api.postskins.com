<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * The authenticated user's wallet balances (integer minor units).
     */
    public function show(Request $request): JsonResponse
    {
        $wallet = $request->user()->ensureWallet();

        return response()->json([
            'balance' => $wallet->balance,
            'locked_balance' => $wallet->locked_balance,
            'currency' => $wallet->currency,
        ]);
    }
}
