<?php

namespace App\Services\Trading\Wallet;

use App\Exceptions\InsufficientFundsException;
use App\Models\Trade;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * The single writer for wallet balances. Every mutation writes one append-only
 * `wallet_transactions` row and updates the cached `wallets` columns inside the
 * same locked transaction, so the ledger and the cache can never diverge.
 */
class Ledger
{
    /**
     * Add spendable funds to a wallet (e.g. a deposit or test credit).
     */
    public function deposit(Wallet $wallet, int $amount): WalletTransaction
    {
        return $this->apply($wallet, 'deposit', balanceDelta: $amount, lockedDelta: 0, trade: null);
    }

    /**
     * Move a buyer's payment out of their spendable balance to hold it for a trade.
     *
     * @throws InsufficientFundsException
     */
    public function hold(Wallet $wallet, int $amount, Trade $trade): WalletTransaction
    {
        return $this->apply($wallet, 'escrow_hold', balanceDelta: -$amount, lockedDelta: 0, trade: $trade);
    }

    /**
     * Return held funds to the buyer's spendable balance (cancellation/reversal).
     */
    public function refund(Wallet $wallet, int $amount, Trade $trade): WalletTransaction
    {
        return $this->apply($wallet, 'escrow_refund', balanceDelta: $amount, lockedDelta: 0, trade: $trade);
    }

    /**
     * Commit the payment to the seller as a locked payout for the protection window.
     */
    public function lockPayout(Wallet $wallet, int $amount, Trade $trade): WalletTransaction
    {
        return $this->apply($wallet, 'payout_locked', balanceDelta: 0, lockedDelta: $amount, trade: $trade);
    }

    /**
     * Release a locked payout into the seller's spendable balance.
     */
    public function releasePayout(Wallet $wallet, int $amount, Trade $trade): WalletTransaction
    {
        return $this->apply($wallet, 'payout_released', balanceDelta: $amount, lockedDelta: -$amount, trade: $trade);
    }

    /**
     * Cancel a locked payout without paying it out (e.g. after a reversal).
     */
    public function voidPayout(Wallet $wallet, int $amount, Trade $trade): WalletTransaction
    {
        return $this->apply($wallet, 'payout_void', balanceDelta: 0, lockedDelta: -$amount, trade: $trade);
    }

    /**
     * Atomically apply a signed change to a wallet and record it in the ledger.
     *
     * @throws InsufficientFundsException
     */
    private function apply(Wallet $wallet, string $type, int $balanceDelta, int $lockedDelta, ?Trade $trade): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $type, $balanceDelta, $lockedDelta, $trade): WalletTransaction {
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->getKey());

            $balance = $wallet->balance + $balanceDelta;
            $locked = $wallet->locked_balance + $lockedDelta;

            if ($balance < 0 || $locked < 0) {
                throw new InsufficientFundsException;
            }

            $wallet->forceFill(['balance' => $balance, 'locked_balance' => $locked])->save();

            return $wallet->transactions()->create([
                'trade_id' => $trade?->getKey(),
                'type' => $type,
                'amount' => $balanceDelta + $lockedDelta,
                'balance_after' => $balance,
                'locked_after' => $locked,
            ]);
        });
    }
}
