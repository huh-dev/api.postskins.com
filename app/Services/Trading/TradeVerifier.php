<?php

namespace App\Services\Trading;

use App\Enums\TradeStatus;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Services\Trading\Wallet\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Verifies a trade against the buyer's (already synced) inventory and advances
 * its lifecycle. Reversal detection is by inventory: during Steam's protection
 * window a received item is trade-locked, so it can only leave via a rollback —
 * present = good, gone = reversal. The caller must only invoke this after a
 * *successful* inventory read; acting on a failed/empty read is never correct.
 */
class TradeVerifier
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Run one verification pass for a trade. Idempotent and safe to call
     * repeatedly; a duplicated call cannot double-pay or double-refund.
     */
    public function verify(Trade $original): Trade
    {
        return DB::transaction(function () use ($original): Trade {
            $trade = Trade::query()->lockForUpdate()->find($original->getKey());

            if ($trade === null || ! $trade->status->isActive()) {
                return $trade ?? $original;
            }

            if ($trade->status === TradeStatus::PendingDelivery) {
                $this->checkDelivery($trade);
            }

            // Fall through so a zero-length protection window can complete in the
            // same pass it was accepted.
            if ($trade->status === TradeStatus::Accepted) {
                $this->checkProtection($trade);
            }

            $trade->last_polled_at = now();
            $trade->save();

            return $trade->refresh();
        });
    }

    /**
     * Look for the item in the buyer's inventory and accept, dispute, or wait.
     */
    private function checkDelivery(Trade $trade): void
    {
        $newlyReceived = InventoryItem::query()
            ->with('itemDescription')
            ->where('user_id', $trade->buyer_id)
            ->where('app_id', $trade->app_id)
            ->where('context_id', $trade->context_id)
            ->where('tradable', false)
            ->where('first_seen_at', '>=', $trade->created_at)
            ->get();

        $match = $newlyReceived->first(
            fn (InventoryItem $item): bool => $item->itemDescription?->market_hash_name === $trade->market_hash_name,
        );

        if ($match !== null) {
            $this->accept($trade, $match);

            return;
        }

        // The seller sent something, but not what was bought → hold for review.
        if ($newlyReceived->isNotEmpty()) {
            $this->dispute($trade, 'wrong_item', [
                'expected' => $trade->market_hash_name,
                'received' => $newlyReceived
                    ->map(fn (InventoryItem $item): ?string => $item->itemDescription?->market_hash_name)
                    ->filter()
                    ->values()
                    ->all(),
            ]);

            return;
        }

        // Nothing yet. Give up only after the maximum escrow hold could elapse.
        if (now()->greaterThan($trade->created_at->addDays((int) config('trades.escrow_max_days')))) {
            $this->cancel($trade, 'not_delivered');

            return;
        }

        $this->reschedulePoll($trade);
    }

    /**
     * The item was received: start the protection window and lock the payout.
     */
    private function accept(Trade $trade, InventoryItem $received): void
    {
        $trade->asset_id_received = $received->asset_id;
        $trade->status = TradeStatus::Accepted;
        $trade->accepted_at = now();
        $trade->protection_expires_at = $this->protectionExpiry();
        // Keep polling through the window so a reversal is caught promptly, not
        // only at expiry.
        $this->reschedulePoll($trade);

        $this->ledger->lockPayout($trade->seller->ensureWallet(), $trade->price, $trade);

        $trade->recordEvent('accepted', [
            'asset_id_received' => $received->asset_id,
            'protection_expires_at' => $trade->protection_expires_at?->toIso8601String(),
        ]);
    }

    /**
     * While protected: still held → complete when the window ends; gone → reversal.
     */
    private function checkProtection(Trade $trade): void
    {
        $stillHeld = InventoryItem::query()
            ->where('user_id', $trade->buyer_id)
            ->where('app_id', $trade->app_id)
            ->where('asset_id', $trade->asset_id_received)
            ->exists();

        if (! $stillHeld) {
            $this->reverse($trade);

            return;
        }

        if ($trade->protection_expires_at !== null && now()->greaterThanOrEqualTo($trade->protection_expires_at)) {
            $this->complete($trade);

            return;
        }

        $this->reschedulePoll($trade);
    }

    /**
     * Protection window survived: pay the seller.
     */
    private function complete(Trade $trade): void
    {
        $trade->status = TradeStatus::Completed;
        $trade->completed_at = now();
        $trade->next_poll_at = null;

        $this->ledger->releasePayout($trade->seller->ensureWallet(), $trade->price, $trade);

        $trade->recordEvent('completed');
    }

    /**
     * The item left the buyer's inventory during the window: reverse everything.
     */
    private function reverse(Trade $trade): void
    {
        $trade->status = TradeStatus::Reversed;
        $trade->reversed_at = now();
        $trade->next_poll_at = null;

        // Void the seller's locked payout and refund the buyer.
        $this->ledger->voidPayout($trade->seller->ensureWallet(), $trade->price, $trade);
        $this->ledger->refund($trade->buyer->ensureWallet(), $trade->price, $trade);

        // Suspend the seller pending manual review.
        $trade->seller->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => "Trade #{$trade->id} reversed after delivery",
        ])->save();

        $trade->recordEvent('reversal', [
            'asset_id_received' => $trade->asset_id_received,
            'detected_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * The buyer received a different item than they paid for.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function dispute(Trade $trade, string $reason, array $evidence = []): void
    {
        $trade->status = TradeStatus::Disputed;
        $trade->next_poll_at = null;

        $trade->recordEvent('disputed', ['reason' => $reason] + $evidence);
    }

    /**
     * The offer was never delivered in time: refund the buyer, no penalty.
     */
    private function cancel(Trade $trade, string $reason): void
    {
        $trade->status = TradeStatus::Cancelled;
        $trade->next_poll_at = null;

        $this->ledger->refund($trade->buyer->ensureWallet(), $trade->price, $trade);

        $trade->recordEvent('cancelled', ['reason' => $reason]);
    }

    /**
     * Schedule the next poll. Simple fixed cadence; the window's final check is
     * pinned to `protection_expires_at` at acceptance time.
     */
    private function reschedulePoll(Trade $trade): void
    {
        $trade->next_poll_at = now()->addSeconds((int) config('trades.poll.min_seconds'));
    }

    /**
     * When the current protection window ends. A seconds override lets local/test
     * runs watch the lifecycle without waiting the full 7 days.
     */
    private function protectionExpiry(): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $seconds = config('trades.protection_hold_seconds');

        return $seconds !== null
            ? $now->addRealSeconds((int) $seconds)
            : $now->addDays((int) config('trades.protection_hold_days'));
    }
}
