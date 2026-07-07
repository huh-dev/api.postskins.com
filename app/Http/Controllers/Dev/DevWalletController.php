<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\Trading\Wallet\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LOCAL-ONLY helper to credit the authenticated user's wallet, standing in for
 * a real deposit/top-up flow while testing the marketplace.
 */
class DevWalletController extends Controller
{
    public function __construct(private readonly Ledger $ledger)
    {
        abort_unless(app()->environment('local'), 404);
    }

    public function credit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'integer', 'min:1', 'max:100000000'],
        ]);

        $wallet = $request->user()->ensureWallet();
        $this->ledger->deposit($wallet, $validated['amount'] ?? 100_000);

        return response()->json([
            'balance' => $wallet->fresh()->balance,
            'currency' => $wallet->currency,
        ]);
    }
}
