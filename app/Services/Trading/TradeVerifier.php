<?php

namespace App\Services\Trading;

use App\Enums\TradeStatus;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Services\Trading\Wallet\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Advances a trade's lifecycle.
 *
 * Delivery (pending -> accepted) is confirmed from the authoritative Steam offer
 * state via `markDelivered()`; the buyer's inventory (which our provider may
 * serve from cache) is only used to watch for a reversal during the protection
 * window. A missing/unseen item is never treated as a reversal — we only reverse
 * when an item we previously observed disappears.
 */
class TradeVerifier
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Delivery confirmed by the Steam offer state: start the protection window
     * and lock the seller's payout. Idempotent.
     */
    public function markDelivered(Trade $original, ?string $steamTradeId = null, bool $escrow = false): Trade
    {
        return DB::transaction(function () use ($original, $steamTradeId, $escrow): Trade {
            $trade = Trade::query()->lockForUpdate()->find($original->getKey());

            if ($trade === null || $trade->status !== TradeStatus::PendingDelivery) {
                return $trade ?? $original;
            }

            $trade->status = TradeStatus::Accepted;
            $trade->accepted_at = now();
            $trade->escrow = $escrow;
            $trade->protection_expires_at = $this->protectionExpiry();
            $this->rescheduleReversalPoll($trade);
            $trade->last_polled_at = now();

            $this->ledger->lockPayout($trade->seller->ensureWallet(), $trade->price, $trade);

            $trade->recordEvent('accepted', [
                'via' => 'offer_state',
                'steam_trade_id' => $steamTradeId,
                'escrow' => $escrow,
                'protection_expires_at' => $trade->protection_expires_at?->toIso8601String(),
            ]);

            $trade->save();

            return $trade->refresh();
        });
    }

    /**
     * The offer never completed (declined/expired/canceled): refund the buyer.
     * Idempotent.
     */
    public function cancelDelivery(Trade $original, string $reason): Trade
    {
        return DB::transaction(function () use ($original, $reason): Trade {
            $trade = Trade::query()->lockForUpdate()->find($original->getKey());

            if ($trade === null || $trade->status !== TradeStatus::PendingDelivery) {
                return $trade ?? $original;
            }

            $this->cancel($trade, $reason);
            $trade->last_polled_at = now();
            $trade->save();

            return $trade->refresh();
        });
    }

    /**
     * A rollback was authoritatively detected: suspend the seller, refund the
     * buyer, void the payout. Idempotent.
     */
    public function markReversed(Trade $original): Trade
    {
        return DB::transaction(function () use ($original): Trade {
            $trade = Trade::query()->lockForUpdate()->find($original->getKey());

            if ($trade === null || $trade->status !== TradeStatus::Accepted) {
                return $trade ?? $original;
            }

            $this->reverse($trade);
            $trade->last_polled_at = now();
            $trade->save();

            return $trade->refresh();
        });
    }

    /**
     * The protection window passed with no rollback: pay the seller. Idempotent.
     */
    public function markCompleted(Trade $original): Trade
    {
        return DB::transaction(function () use ($original): Trade {
            $trade = Trade::query()->lockForUpdate()->find($original->getKey());

            if ($trade === null || $trade->status !== TradeStatus::Accepted) {
                return $trade ?? $original;
            }

            $this->complete($trade);
            $trade->last_polled_at = now();
            $trade->save();

            return $trade->refresh();
        });
    }

    /**
     * Session-free protection-window review from the two parties' PUBLIC
     * inventories (no seller cooperation required — a scamming seller can't
     * withhold this). Reversal is detected when the sold asset returns to the
     * seller, or the buyer's received copy disappears while trade-locked.
     * Idempotent.
     *
     * @param  bool  $sellerReadOk  Whether the seller inventory read succeeded.
     * @param  bool  $buyerReadOk  Whether the buyer inventory read succeeded.
     */
    public function reviewAcceptedTrade(Trade $original, bool $sellerReadOk, bool $buyerReadOk): Trade
    {
        return DB::transaction(function () use ($original, $sellerReadOk, $buyerReadOk): Trade {
            $trade = Trade::query()->lockForUpdate()->find($original->getKey());

            if ($trade === null || $trade->status !== TradeStatus::Accepted) {
                return $trade ?? $original;
            }

            // Primary signal: the sold asset is back in the seller's inventory.
            // After a normal sale that asset id is gone for good; it only returns
            // on a rollback.
            if ($sellerReadOk && $this->sellerHasItemBack($trade)) {
                $this->reverse($trade);

                return $this->save($trade);
            }

            // Secondary signal: the buyer's received (trade-locked) copy vanished.
            if ($buyerReadOk) {
                if ($trade->asset_id_received === null) {
                    $received = $this->matchReceived($this->newlyReceivedLockedItems($trade), $trade);
                    if ($received !== null) {
                        $trade->asset_id_received = $received->asset_id;
                    }
                } elseif (! $this->buyerStillHolds($trade)) {
                    $this->reverse($trade);

                    return $this->save($trade);
                }
            }

            // Complete only when the window has elapsed AND we could confirm the
            // item did not return to the seller.
            if ($sellerReadOk && $this->windowElapsed($trade)) {
                $this->complete($trade);
            } else {
                $this->rescheduleReversalPoll($trade);
            }

            return $this->save($trade);
        });
    }

    /**
     * Run one inventory-based verification pass. Used for reversal/completion
     * during the protection window, and as a fallback delivery check when no
     * Steam offer id is known. Idempotent.
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
     * Inventory-based delivery fallback: accept, dispute, or wait.
     */
    private function checkDelivery(Trade $trade): void
    {
        $newlyReceived = $this->newlyReceivedLockedItems($trade);
        $match = $this->matchReceived($newlyReceived, $trade);

        if ($match !== null) {
            $this->accept($trade, $match);

            return;
        }

        // Something arrived, but not what was bought → hold for review.
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
     * The item was found in the buyer's inventory: accept (fallback path).
     */
    private function accept(Trade $trade, InventoryItem $received): void
    {
        $trade->asset_id_received = $received->asset_id;
        $trade->status = TradeStatus::Accepted;
        $trade->accepted_at = now();
        $trade->protection_expires_at = $this->protectionExpiry();
        $this->reschedulePoll($trade);

        $this->ledger->lockPayout($trade->seller->ensureWallet(), $trade->price, $trade);

        $trade->recordEvent('accepted', [
            'via' => 'inventory',
            'asset_id_received' => $received->asset_id,
            'protection_expires_at' => $trade->protection_expires_at?->toIso8601String(),
        ]);
    }

    /**
     * During the window: complete when it ends; reverse only if a previously
     * observed item disappears.
     */
    private function checkProtection(Trade $trade): void
    {
        // If delivery was confirmed by offer state before the inventory caught
        // up, capture which asset to watch as soon as it appears.
        if ($trade->asset_id_received === null) {
            $received = $this->matchReceived($this->newlyReceivedLockedItems($trade), $trade);

            if ($received !== null) {
                $trade->asset_id_received = $received->asset_id;
            } else {
                // Item not visible yet (cached/private read). Never treat an
                // unseen item as a reversal: complete once the window elapses,
                // otherwise keep waiting.
                $this->windowElapsed($trade) ? $this->complete($trade) : $this->reschedulePoll($trade);

                return;
            }
        }

        $stillHeld = InventoryItem::query()
            ->where('user_id', $trade->buyer_id)
            ->where('app_id', $trade->app_id)
            ->where('asset_id', $trade->asset_id_received)
            ->exists();

        if (! $stillHeld) {
            $this->reverse($trade);

            return;
        }

        if ($this->windowElapsed($trade)) {
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
     * A previously observed item left the buyer's inventory during the window.
     */
    private function reverse(Trade $trade): void
    {
        $trade->status = TradeStatus::Reversed;
        $trade->reversed_at = now();
        $trade->next_poll_at = null;

        $this->ledger->voidPayout($trade->seller->ensureWallet(), $trade->price, $trade);
        $this->ledger->refund($trade->buyer->ensureWallet(), $trade->price, $trade);

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
     * The offer was never delivered: refund the buyer, no penalty.
     */
    private function cancel(Trade $trade, string $reason): void
    {
        $trade->status = TradeStatus::Cancelled;
        $trade->next_poll_at = null;

        $this->ledger->refund($trade->buyer->ensureWallet(), $trade->price, $trade);

        $trade->recordEvent('cancelled', ['reason' => $reason]);
    }

    /**
     * Buyer's newly-received, trade-locked items (candidates for this trade).
     *
     * @return Collection<int, InventoryItem>
     */
    private function newlyReceivedLockedItems(Trade $trade): Collection
    {
        return InventoryItem::query()
            ->with('itemDescription')
            ->where('user_id', $trade->buyer_id)
            ->where('app_id', $trade->app_id)
            ->where('context_id', $trade->context_id)
            ->where('tradable', false)
            ->where('first_seen_at', '>=', $trade->created_at)
            ->get();
    }

    /**
     * The received item matching this trade's market hash name, if present.
     *
     * @param  Collection<int, InventoryItem>  $items
     */
    private function matchReceived(Collection $items, Trade $trade): ?InventoryItem
    {
        return $items->first(
            fn (InventoryItem $item): bool => $item->itemDescription?->market_hash_name === $trade->market_hash_name,
        );
    }

    private function windowElapsed(Trade $trade): bool
    {
        return $trade->protection_expires_at !== null
            && now()->greaterThanOrEqualTo($trade->protection_expires_at);
    }

    /**
     * The exact sold asset is back in the seller's inventory (rollback signal).
     */
    private function sellerHasItemBack(Trade $trade): bool
    {
        return InventoryItem::query()
            ->where('user_id', $trade->seller_id)
            ->where('app_id', $trade->app_id)
            ->where('asset_id', $trade->asset_id_listed)
            ->exists();
    }

    /**
     * The buyer still holds the received copy we captured.
     */
    private function buyerStillHolds(Trade $trade): bool
    {
        return InventoryItem::query()
            ->where('user_id', $trade->buyer_id)
            ->where('app_id', $trade->app_id)
            ->where('asset_id', $trade->asset_id_received)
            ->exists();
    }

    /**
     * Persist the trade after a review pass and return the fresh model.
     */
    private function save(Trade $trade): Trade
    {
        $trade->last_polled_at = now();
        $trade->save();

        return $trade->refresh();
    }

    private function reschedulePoll(Trade $trade): void
    {
        $trade->next_poll_at = now()->addSeconds((int) config('trades.poll.min_seconds'));
    }

    /**
     * Slower cadence for the protection-window rollback checks (each check uses
     * the seller's Steam session, so we don't want to poll every minute).
     */
    private function rescheduleReversalPoll(Trade $trade): void
    {
        $trade->next_poll_at = now()->addSeconds((int) config('trades.poll.reversal_seconds'));
    }

    private function protectionExpiry(): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $seconds = config('trades.protection_hold_seconds');

        return $seconds !== null
            ? $now->addRealSeconds((int) $seconds)
            : $now->addDays((int) config('trades.protection_hold_days'));
    }
}
