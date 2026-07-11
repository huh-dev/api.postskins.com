<?php

namespace App\Services\Trading;

use App\Enums\TradeStatus;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Models\User;
use App\Services\Trading\Wallet\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Advances a trade's lifecycle across all of its legs.
 *
 * A trade moves several assets in both directions via one atomic Steam offer.
 * Delivery (pending -> accepted) is confirmed from the authoritative Steam offer
 * state via `markDelivered()`; each party's public inventory is only used to
 * watch for a reversal during the protection window and to capture which assets
 * to watch.
 *
 * Two invariants keep this safe and must never be broken:
 *  - An unseen item is never treated as a reversal. We reverse only when an item
 *    we previously observed disappears, or a sent item reappears with its giver.
 *  - Completion requires every receiver's inventory read to have succeeded, so an
 *    outage can never wrongly release funds.
 */
class TradeVerifier
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Delivery confirmed by the Steam offer state: start the protection window
     * and lock the cash payout. Idempotent.
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

            $this->lockPayout($trade);

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
     * The offer never completed (declined/expired/canceled): refund the payer.
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
     * A rollback was authoritatively detected: unwind cash and blame it.
     * Idempotent.
     */
    public function markReversed(Trade $original): Trade
    {
        return DB::transaction(function () use ($original): Trade {
            $trade = Trade::query()->lockForUpdate()->with('items')->find($original->getKey());

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
     * The protection window passed with no rollback: pay the cash payee. Idempotent.
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
     * Session-free protection-window review from the parties' PUBLIC inventories
     * (no cooperation required — a scamming party can't withhold this). Idempotent.
     *
     * @param  array<int, bool>  $readOk  Map of user_id => whether that user's inventory read succeeded.
     */
    public function reviewAcceptedTrade(Trade $original, array $readOk): Trade
    {
        return DB::transaction(function () use ($original, $readOk): Trade {
            $trade = Trade::query()->lockForUpdate()->with('items')->find($original->getKey());

            if ($trade === null || $trade->status !== TradeStatus::Accepted) {
                return $trade ?? $original;
            }

            $this->runReview($trade, fn (int $userId): bool => $readOk[$userId] ?? false);

            return $this->save($trade);
        });
    }

    /**
     * Run one inventory-based verification pass. Used as a fallback delivery
     * check and protection review when no Steam offer id is known (lab/manual).
     * The caller must have refreshed every involved inventory first, so reads are
     * treated as clean here. Idempotent.
     */
    public function verify(Trade $original): Trade
    {
        return DB::transaction(function () use ($original): Trade {
            $trade = Trade::query()->lockForUpdate()->with('items')->find($original->getKey());

            if ($trade === null || ! $trade->status->isActive()) {
                return $trade ?? $original;
            }

            if ($trade->status === TradeStatus::PendingDelivery) {
                $this->checkDelivery($trade);
            }

            // Fall through so a zero-length protection window can complete in the
            // same pass it was accepted.
            if ($trade->status === TradeStatus::Accepted) {
                $trade->load('items');
                $this->runReview($trade, fn (int $userId): bool => true);
            }

            $trade->last_polled_at = now();
            $trade->save();

            return $trade->refresh();
        });
    }

    /**
     * The protection-window brain, shared by the offer path and the fallback.
     *
     * @param  callable(int): bool  $readOk  Whether a user's inventory read is trustworthy this pass.
     */
    private function runReview(Trade $trade, callable $readOk): void
    {
        $legs = $trade->items;

        // Reversal signal A: a sent asset is back with its giver. After a normal
        // move that asset id is gone for good; it only returns on a rollback.
        foreach ($legs as $leg) {
            if ($readOk($leg->giver_id) && $this->givenItemBack($leg)) {
                $this->reverse($trade);

                return;
            }
        }

        $this->captureReceivedAssets($trade, $readOk);

        // Reversal signal B: a received copy we captured has vanished.
        foreach ($legs as $leg) {
            if ($leg->asset_id_received !== null && $readOk($leg->receiver_id) && ! $this->receivedItemStillHeld($leg)) {
                $this->reverse($trade);

                return;
            }
        }

        // Complete only when the window elapsed AND every receiver read cleanly,
        // so we can affirm nothing came back.
        $everyReceiverRead = $this->distinctReceiverIds($trade)->every(fn (int $id): bool => $readOk($id));

        if ($this->windowElapsed($trade) && $everyReceiverRead) {
            $this->complete($trade);
        } else {
            $this->rescheduleReversalPoll($trade);
        }
    }

    /**
     * Bind each not-yet-seen leg to a matching newly-received locked item in its
     * receiver's inventory, never binding one asset to two legs.
     *
     * @param  callable(int): bool  $readOk
     */
    private function captureReceivedAssets(Trade $trade, callable $readOk): void
    {
        // Seed the used set with assets already captured on sibling legs.
        $usedByReceiver = [];
        foreach ($trade->items as $leg) {
            if ($leg->asset_id_received !== null) {
                $usedByReceiver[$leg->receiver_id][] = $leg->asset_id_received;
            }
        }

        foreach ($trade->items as $leg) {
            if ($leg->asset_id_received !== null || ! $readOk($leg->receiver_id)) {
                continue;
            }

            $used = $usedByReceiver[$leg->receiver_id] ?? [];
            $match = $this->newlyReceivedLockedItems($trade, $leg->receiver_id)->first(
                fn (InventoryItem $item): bool => $item->itemDescription?->market_hash_name === $leg->market_hash_name
                    && ! in_array($item->asset_id, $used, true),
            );

            if ($match !== null) {
                $leg->asset_id_received = $match->asset_id;
                $leg->received_seen_at = now();
                $leg->save();
                $usedByReceiver[$leg->receiver_id][] = $match->asset_id;
            }
        }
    }

    /**
     * Inventory-based delivery fallback: accept when every leg has arrived,
     * dispute on a wrong item, cancel after the escrow timeout, else wait.
     */
    private function checkDelivery(Trade $trade): void
    {
        $wrongItem = false;

        foreach ($trade->items->groupBy('receiver_id') as $receiverId => $receiverLegs) {
            $candidates = $this->newlyReceivedLockedItems($trade, (int) $receiverId);
            $expectedHashes = $receiverLegs->pluck('market_hash_name');
            $used = $receiverLegs->pluck('asset_id_received')->filter()->values()->all();

            foreach ($receiverLegs as $leg) {
                if ($leg->asset_id_received !== null) {
                    continue;
                }

                $match = $candidates->first(
                    fn (InventoryItem $item): bool => $item->itemDescription?->market_hash_name === $leg->market_hash_name
                        && ! in_array($item->asset_id, $used, true),
                );

                if ($match !== null) {
                    $leg->asset_id_received = $match->asset_id;
                    $leg->received_seen_at = now();
                    $leg->save();
                    $used[] = $match->asset_id;
                }
            }

            // A locked arrival whose hash no leg expected → something is wrong.
            if ($candidates->contains(fn (InventoryItem $item): bool => ! $expectedHashes->contains($item->itemDescription?->market_hash_name))) {
                $wrongItem = true;
            }
        }

        if ($trade->items->every(fn (TradeItem $leg): bool => $leg->asset_id_received !== null)) {
            $this->accept($trade);

            return;
        }

        if ($wrongItem) {
            $this->dispute($trade, 'wrong_item', [
                'expected' => $trade->items->pluck('market_hash_name')->unique()->values()->all(),
            ]);

            return;
        }

        // Give up only after the maximum escrow hold could elapse.
        if (now()->greaterThan($trade->created_at->addDays((int) config('trades.escrow_max_days')))) {
            $this->cancel($trade, 'not_delivered');

            return;
        }

        $this->reschedulePoll($trade);
    }

    /**
     * Every leg was found in its receiver's inventory: accept (fallback path).
     */
    private function accept(Trade $trade): void
    {
        $trade->status = TradeStatus::Accepted;
        $trade->accepted_at = now();
        $trade->protection_expires_at = $this->protectionExpiry();
        $this->reschedulePoll($trade);

        $this->lockPayout($trade);

        $trade->recordEvent('accepted', [
            'via' => 'inventory',
            'protection_expires_at' => $trade->protection_expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Protection window survived: pay the cash payee.
     */
    private function complete(Trade $trade): void
    {
        $trade->status = TradeStatus::Completed;
        $trade->completed_at = now();
        $trade->next_poll_at = null;

        if ($trade->cash_amount > 0 && $trade->cash_payee_id !== null) {
            $this->ledger->releasePayout(User::findOrFail($trade->cash_payee_id)->ensureWallet(), $trade->cash_amount, $trade);
        }

        $trade->recordEvent('completed');
    }

    /**
     * A rollback happened during the window: unwind cash and assign blame.
     *
     * A pure cash purchase (the counterparty gave no items) can only have been
     * rolled back by the initiator, so we suspend them as before. A two-sided
     * swap can't be blamed from inventory alone — flag it for manual review.
     */
    private function reverse(Trade $trade): void
    {
        $trade->status = TradeStatus::Reversed;
        $trade->reversed_at = now();
        $trade->next_poll_at = null;

        if ($trade->cash_amount > 0) {
            $this->ledger->voidPayout(User::findOrFail($trade->cash_payee_id)->ensureWallet(), $trade->cash_amount, $trade);
            $this->ledger->refund(User::findOrFail($trade->cash_payer_id)->ensureWallet(), $trade->cash_amount, $trade);
        }

        $isCashPurchase = $trade->isCashPurchase();
        $trade->needs_review = ! $isCashPurchase;

        if ($isCashPurchase) {
            $trade->initiator->forceFill([
                'suspended_at' => now(),
                'suspension_reason' => "Trade #{$trade->id} reversed after delivery",
            ])->save();
        }

        $trade->recordEvent('reversal', [
            'needs_review' => $trade->needs_review,
            'suspended_user_id' => $isCashPurchase ? $trade->initiator_id : null,
            'detected_at' => now()->toIso8601String(),
        ]);

        if ($trade->needs_review) {
            $trade->recordEvent('reversal_review', [
                'note' => 'A two-sided swap was reversed; a human must decide who is at fault.',
            ]);
        }
    }

    /**
     * A receiver got a different item than the trade specified.
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
     * The offer was never delivered: refund the payer, no penalty.
     */
    private function cancel(Trade $trade, string $reason): void
    {
        $trade->status = TradeStatus::Cancelled;
        $trade->next_poll_at = null;

        if ($trade->cash_amount > 0 && $trade->cash_payer_id !== null) {
            $this->ledger->refund(User::findOrFail($trade->cash_payer_id)->ensureWallet(), $trade->cash_amount, $trade);
        }

        $trade->recordEvent('cancelled', ['reason' => $reason]);
    }

    /**
     * Commit the cash payout to the payee as a locked balance for the window.
     */
    private function lockPayout(Trade $trade): void
    {
        if ($trade->cash_amount > 0 && $trade->cash_payee_id !== null) {
            $this->ledger->lockPayout(User::findOrFail($trade->cash_payee_id)->ensureWallet(), $trade->cash_amount, $trade);
        }
    }

    /**
     * A receiver's newly-received, trade-locked items (delivery candidates).
     *
     * @return Collection<int, InventoryItem>
     */
    private function newlyReceivedLockedItems(Trade $trade, int $receiverId): Collection
    {
        return InventoryItem::query()
            ->with('itemDescription')
            ->where('user_id', $receiverId)
            ->where('app_id', $trade->app_id)
            ->where('context_id', $trade->context_id)
            ->where('tradable', false)
            ->where('first_seen_at', '>=', $trade->created_at)
            ->get();
    }

    /**
     * The exact sent asset is back with its giver (rollback signal).
     */
    private function givenItemBack(TradeItem $leg): bool
    {
        return InventoryItem::query()
            ->where('user_id', $leg->giver_id)
            ->where('app_id', $leg->app_id)
            ->where('asset_id', $leg->asset_id_sent)
            ->exists();
    }

    /**
     * The receiver still holds the copy we captured for this leg.
     */
    private function receivedItemStillHeld(TradeItem $leg): bool
    {
        return InventoryItem::query()
            ->where('user_id', $leg->receiver_id)
            ->where('app_id', $leg->app_id)
            ->where('asset_id', $leg->asset_id_received)
            ->exists();
    }

    /**
     * @return Collection<int, int>
     */
    private function distinctReceiverIds(Trade $trade): Collection
    {
        return $trade->items->pluck('receiver_id')->map(fn ($id): int => (int) $id)->unique()->values();
    }

    private function windowElapsed(Trade $trade): bool
    {
        return $trade->protection_expires_at !== null
            && now()->greaterThanOrEqualTo($trade->protection_expires_at);
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
     * Slower cadence for protection-window rollback checks.
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
