<?php

namespace App\Services\Trading;

use App\Enums\TradeItemSide;
use App\Enums\TradeOfferStatus;
use App\Enums\TradePostStatus;
use App\Enums\TradeStatus;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\TradeExecutionException;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\Models\TradeOfferItem;
use App\Models\TradePost;
use App\Models\User;
use App\Services\Trading\Wallet\Ledger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Executes an accepted trade offer.
 *
 * The post owner is always the initiator (they send one atomic Steam offer
 * carrying both parties' items); the offerer is the counterparty. Cash from the
 * offer is netted into a single directional hold so the ledger stays a plain
 * transfer. This is the atomic authority — cheap pre-checks live in the caller,
 * but every invariant that must hold at commit time is re-verified here under a
 * row lock.
 */
class TradeService
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Turn a pending offer into a live trade: lock the post and offer, verify
     * both parties still hold every asset, hold any cash, create the trade and
     * its legs, and decline the sibling offers. Idempotency is enforced by the
     * post/offer status guards under `lockForUpdate`, so a double-accept race
     * produces exactly one trade.
     *
     * @throws TradeExecutionException
     * @throws InsufficientFundsException
     */
    public function execute(TradeOffer $offer): Trade
    {
        return DB::transaction(function () use ($offer): Trade {
            $offer = TradeOffer::query()->lockForUpdate()->find($offer->getKey());
            $post = $offer !== null
                ? TradePost::query()->lockForUpdate()->find($offer->trade_post_id)
                : null;

            if ($post === null || $post->status !== TradePostStatus::Open) {
                throw TradeExecutionException::postUnavailable();
            }

            if ($offer->status !== TradeOfferStatus::Pending) {
                throw TradeExecutionException::offerUnavailable();
            }

            $initiator = $post->owner;
            $counterparty = $offer->offerer;

            $this->assertPartiesEligible($initiator, $counterparty);

            $offererItems = $offer->offererItems()->get();
            $posterItems = $offer->posterItems()->get();

            $this->assertItemsAvailable($initiator, $posterItems);
            $this->assertItemsAvailable($counterparty, $offererItems);

            [$cashAmount, $payerId, $payeeId] = $this->netCash($offer, $initiator, $counterparty);

            $trade = Trade::create([
                'trade_post_id' => $post->id,
                'trade_offer_id' => $offer->id,
                'initiator_id' => $initiator->id,
                'counterparty_id' => $counterparty->id,
                'app_id' => $post->app_id,
                'context_id' => $post->context_id,
                'cash_amount' => $cashAmount,
                'cash_payer_id' => $payerId,
                'cash_payee_id' => $payeeId,
                'currency' => $post->currency,
                'status' => TradeStatus::PendingDelivery,
                'next_poll_at' => now(),
            ]);

            $this->createLegs($trade, TradeItemSide::FromInitiator, $initiator, $counterparty, $posterItems);
            $this->createLegs($trade, TradeItemSide::FromCounterparty, $counterparty, $initiator, $offererItems);

            if ($cashAmount > 0) {
                // May throw InsufficientFundsException, rolling back the whole trade.
                $this->ledger->hold(User::find($payerId)->ensureWallet(), $cashAmount, $trade);
            }

            $post->forceFill([
                'status' => TradePostStatus::Fulfilled,
                'accepted_offer_id' => $offer->id,
            ])->save();

            $offer->forceFill([
                'status' => TradeOfferStatus::Accepted,
                'trade_id' => $trade->id,
            ])->save();

            TradeOffer::query()
                ->where('trade_post_id', $post->id)
                ->where('id', '!=', $offer->id)
                ->where('status', TradeOfferStatus::Pending)
                ->update(['status' => TradeOfferStatus::Declined]);

            $trade->recordEvent('created', [
                'trade_post_id' => $post->id,
                'trade_offer_id' => $offer->id,
                'cash_amount' => $cashAmount,
                'initiator_items' => $posterItems->count(),
                'counterparty_items' => $offererItems->count(),
            ]);

            return $trade;
        });
    }

    /**
     * @throws TradeExecutionException
     */
    private function assertPartiesEligible(User $initiator, User $counterparty): void
    {
        if ($initiator->id === $counterparty->id) {
            throw TradeExecutionException::selfTrade();
        }

        if (! $initiator->isSellingConnected()) {
            throw TradeExecutionException::initiatorNotConnected();
        }

        if (! $counterparty->trade_url) {
            throw TradeExecutionException::counterpartyMissingTradeUrl();
        }

        if ($initiator->isSuspended() || $counterparty->isSuspended()) {
            throw TradeExecutionException::partySuspended();
        }
    }

    /**
     * Every asset must still be in the owner's synced inventory and tradable.
     *
     * @param  Collection<int, TradeOfferItem>  $items
     *
     * @throws TradeExecutionException
     */
    private function assertItemsAvailable(User $owner, Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $assetIds = $items->pluck('asset_id')->all();

        $held = InventoryItem::query()
            ->where('user_id', $owner->id)
            ->where('tradable', true)
            ->whereIn('asset_id', $assetIds)
            ->pluck('asset_id')
            ->all();

        $missing = array_values(array_diff($assetIds, $held));

        if ($missing !== []) {
            throw TradeExecutionException::itemsUnavailable($missing);
        }
    }

    /**
     * Resolve the offer's netted cash into a directional payer/payee.
     *
     * @return array{0: int, 1: int|null, 2: int|null}
     */
    private function netCash(TradeOffer $offer, User $initiator, User $counterparty): array
    {
        if ($offer->cash_amount <= 0) {
            return [0, null, null];
        }

        // The initiator is the post owner; the counterparty is the offerer.
        return $offer->cash_payer === TradeOffer::PAYER_OFFERER
            ? [$offer->cash_amount, $counterparty->id, $initiator->id]
            : [$offer->cash_amount, $initiator->id, $counterparty->id];
    }

    /**
     * @param  Collection<int, TradeOfferItem>  $items
     */
    private function createLegs(Trade $trade, TradeItemSide $side, User $giver, User $receiver, Collection $items): void
    {
        foreach ($items as $item) {
            $trade->items()->create([
                'side' => $side,
                'giver_id' => $giver->id,
                'receiver_id' => $receiver->id,
                'item_description_id' => $item->item_description_id,
                'app_id' => $item->app_id,
                'context_id' => $item->context_id,
                'market_hash_name' => $item->market_hash_name,
                'asset_id_sent' => $item->asset_id,
            ]);
        }
    }
}
