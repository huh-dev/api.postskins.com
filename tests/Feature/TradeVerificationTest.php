<?php

use App\Enums\TradeStatus;
use App\Jobs\CheckTradeOffer;
use App\Jobs\VerifyAcceptedTrade;
use App\Jobs\VerifyTradeInventories;
use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\Trade;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Steam\FakeInventoryProvider;
use App\Services\SteamInventorySync;
use App\Services\Trading\GcClient;
use App\Services\Trading\TradeVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const CP_START = 10_000;
const TRADE_PRICE = 2_500;

/**
 * Move each leg on Steam: it leaves its giver and arrives (trade-locked) with
 * its receiver. Mirrors the atomic offer completing.
 */
function moveAllLegs(Trade $trade): void
{
    foreach ($trade->items()->get() as $leg) {
        InventoryItem::where('user_id', $leg->giver_id)
            ->where('asset_id', $leg->asset_id_sent)
            ->delete();

        InventoryItem::create([
            'user_id' => $leg->receiver_id,
            'steam_id' => (string) $leg->receiver_id,
            'app_id' => $leg->app_id,
            'context_id' => $leg->context_id,
            'asset_id' => 'rx-'.$leg->id,
            'item_description_id' => $leg->item_description_id,
            'amount' => 1,
            'tradable' => false,
            'tradable_after' => now()->addDays(7),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}

/**
 * Total money is conserved: the payer starts with CP_START, the payee at zero.
 */
function assertCashConserved(Trade $trade): void
{
    $payer = $trade->cashPayer->ensureWallet()->fresh();
    $payee = $trade->cashPayee->ensureWallet()->fresh();

    expect($payer->balance + $payee->balance + $payee->locked_balance)->toBe(CP_START);
}

/**
 * A pending cash purchase with a connected initiator and a sent offer id.
 *
 * @return array{trade: Trade, initiator: User, counterparty: User}
 */
function offerReadyPurchase(): array
{
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = executeCashPurchase(TRADE_PRICE, CP_START);
    $trade->forceFill(['steam_tradeoffer_id' => '9215285887'])->save();

    return ['trade' => $trade->refresh(), 'initiator' => $initiator, 'counterparty' => $counterparty];
}

/**
 * An accepted cash purchase with the offer delivered and the item moved.
 *
 * @return array{trade: Trade, initiator: User, counterparty: User}
 */
function acceptedPurchase(): array
{
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = offerReadyPurchase();
    moveAllLegs($trade);
    app(TradeVerifier::class)->markDelivered($trade, '734797345521949745', false);

    return ['trade' => $trade->refresh(), 'initiator' => $initiator, 'counterparty' => $counterparty];
}

test('holding the payer funds happens at execute time', function () {
    ['counterparty' => $counterparty] = executeCashPurchase(TRADE_PRICE, CP_START);

    expect($counterparty->ensureWallet()->fresh()->balance)->toBe(CP_START - TRADE_PRICE);
});

test('a pure item swap performs no ledger writes', function () {
    ['trade' => $trade] = executeSwap();

    expect($trade->cash_amount)->toBe(0)
        ->and($trade->cash_payer_id)->toBeNull()
        ->and(WalletTransaction::where('trade_id', $trade->id)->count())->toBe(0);
});

test('delivery is detected and the payee payout is locked', function () {
    ['trade' => $trade, 'initiator' => $initiator] = executeCashPurchase(TRADE_PRICE, CP_START);

    moveAllLegs($trade);
    app(TradeVerifier::class)->verify($trade);

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Accepted)
        ->and($initiator->ensureWallet()->fresh()->locked_balance)->toBe(TRADE_PRICE);

    assertCashConserved($trade);
});

test('the payout is released after the protection window passes', function () {
    config()->set('trades.protection_hold_seconds', 0);

    ['trade' => $trade, 'initiator' => $initiator] = executeCashPurchase(TRADE_PRICE, CP_START);

    moveAllLegs($trade);
    app(TradeVerifier::class)->verify($trade);

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Completed)
        ->and($initiator->ensureWallet()->fresh()->balance)->toBe(TRADE_PRICE)
        ->and($initiator->ensureWallet()->fresh()->locked_balance)->toBe(0);

    assertCashConserved($trade);
});

test('a cash purchase reversal suspends the initiator and refunds the payer', function () {
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = executeCashPurchase(TRADE_PRICE, CP_START);

    moveAllLegs($trade);
    app(TradeVerifier::class)->verify($trade);
    expect($trade->refresh()->status)->toBe(TradeStatus::Accepted);

    // The delivered item leaves the counterparty during the window -> reversal.
    $leg = $trade->items()->first();
    InventoryItem::where('user_id', $leg->receiver_id)->where('asset_id', $leg->asset_id_received)->delete();
    app(TradeVerifier::class)->verify($trade);

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Reversed)
        ->and($trade->needs_review)->toBeFalse()
        ->and($initiator->fresh()->isSuspended())->toBeTrue()
        ->and($initiator->ensureWallet()->fresh()->locked_balance)->toBe(0)
        ->and($counterparty->ensureWallet()->fresh()->balance)->toBe(CP_START);

    expect($trade->events()->where('type', 'reversal')->exists())->toBeTrue();
    assertCashConserved($trade);
});

test('a swap reversal flags for review and suspends nobody', function () {
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = executeSwap(cash: 500, cashPayer: 'offerer', counterpartyBalance: CP_START);

    moveAllLegs($trade);
    app(TradeVerifier::class)->verify($trade);
    expect($trade->refresh()->status)->toBe(TradeStatus::Accepted);

    // One received leg vanishes -> reversal, but blame is unclear for a swap.
    $leg = $trade->items()->whereNotNull('asset_id_received')->first();
    InventoryItem::where('user_id', $leg->receiver_id)->where('asset_id', $leg->asset_id_received)->delete();
    app(TradeVerifier::class)->verify($trade);

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Reversed)
        ->and($trade->needs_review)->toBeTrue()
        ->and($initiator->fresh()->isSuspended())->toBeFalse()
        ->and($counterparty->fresh()->isSuspended())->toBeFalse()
        ->and($trade->events()->where('type', 'reversal_review')->exists())->toBeTrue();
});

test('a wrong item is held for manual review as a dispute', function () {
    ['trade' => $trade, 'counterparty' => $counterparty] = executeCashPurchase(TRADE_PRICE, CP_START);

    // A different (locked) item arrives instead of the one that was bought.
    $other = ItemDescription::factory()->create(['market_hash_name' => 'Glock-18 | Fade (Factory New)']);
    $leg = $trade->items()->first();
    InventoryItem::create([
        'user_id' => $leg->receiver_id,
        'steam_id' => (string) $leg->receiver_id,
        'app_id' => 730,
        'context_id' => 2,
        'asset_id' => 'rx-wrong',
        'item_description_id' => $other->id,
        'amount' => 1,
        'tradable' => false,
        'tradable_after' => now()->addDays(7),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    app(TradeVerifier::class)->verify($trade);

    expect($trade->refresh()->status)->toBe(TradeStatus::Disputed)
        ->and($counterparty->ensureWallet()->fresh()->balance)->toBe(CP_START - TRADE_PRICE);
});

test('an undelivered trade is cancelled and refunded after the escrow window', function () {
    ['trade' => $trade, 'counterparty' => $counterparty] = executeCashPurchase(TRADE_PRICE, CP_START);

    $this->travel(16)->days();
    app(TradeVerifier::class)->verify($trade);
    $this->travelBack();

    expect($trade->refresh()->status)->toBe(TradeStatus::Cancelled)
        ->and($counterparty->ensureWallet()->fresh()->balance)->toBe(CP_START);

    assertCashConserved($trade);
});

test('a failed inventory read never advances a trade', function () {
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = executeCashPurchase(TRADE_PRICE, CP_START);

    FakeInventoryProvider::set($initiator->steam_id, 'error');
    FakeInventoryProvider::set($counterparty->steam_id, 'error');

    (new VerifyTradeInventories($trade->id))->handle(
        app(FakeInventoryProvider::class),
        app(SteamInventorySync::class),
        app(TradeVerifier::class),
    );

    expect($trade->refresh()->status)->toBe(TradeStatus::PendingDelivery)
        ->and($initiator->ensureWallet()->fresh()->locked_balance)->toBe(0);
});

test('markDelivered accepts the trade and locks the payee payout', function () {
    ['trade' => $trade, 'initiator' => $initiator] = executeCashPurchase(TRADE_PRICE, CP_START);

    app(TradeVerifier::class)->markDelivered($trade, '734797345521949745', false);

    expect($trade->refresh()->status)->toBe(TradeStatus::Accepted)
        ->and($initiator->ensureWallet()->fresh()->locked_balance)->toBe(TRADE_PRICE);

    assertCashConserved($trade->refresh());
});

test('a delivered trade completes after the window even if the inventory never shows the item', function () {
    config()->set('trades.protection_hold_seconds', 0);
    ['trade' => $trade, 'initiator' => $initiator] = executeCashPurchase(TRADE_PRICE, CP_START);
    moveAllLegs($trade);

    app(TradeVerifier::class)->markDelivered($trade, null, false);
    app(TradeVerifier::class)->verify($trade);

    expect($trade->refresh()->status)->toBe(TradeStatus::Completed)
        ->and($initiator->ensureWallet()->fresh()->balance)->toBe(TRADE_PRICE);
});

test('cancelDelivery refunds the payer', function () {
    ['trade' => $trade, 'counterparty' => $counterparty] = executeCashPurchase(TRADE_PRICE, CP_START);

    app(TradeVerifier::class)->cancelDelivery($trade, 'offer_declined');

    expect($trade->refresh()->status)->toBe(TradeStatus::Cancelled)
        ->and($counterparty->ensureWallet()->fresh()->balance)->toBe(CP_START);
});

test('CheckTradeOffer marks the trade delivered when the offer is accepted', function () {
    Http::fake(['*/trade/offer-state' => Http::response(['ok' => true, 'state' => 3, 'stateName' => 'Accepted', 'tradeId' => '734797345521949745'])]);

    ['trade' => $trade, 'initiator' => $initiator] = offerReadyPurchase();

    (new CheckTradeOffer($trade->id))->handle(app(GcClient::class), app(TradeVerifier::class));

    expect($trade->refresh()->status)->toBe(TradeStatus::Accepted)
        ->and($initiator->ensureWallet()->fresh()->locked_balance)->toBe(TRADE_PRICE);
});

test('CheckTradeOffer refunds the payer when the offer is declined', function () {
    Http::fake(['*/trade/offer-state' => Http::response(['ok' => true, 'state' => 7, 'stateName' => 'Declined'])]);

    ['trade' => $trade, 'counterparty' => $counterparty] = offerReadyPurchase();

    (new CheckTradeOffer($trade->id))->handle(app(GcClient::class), app(TradeVerifier::class));

    expect($trade->refresh()->status)->toBe(TradeStatus::Cancelled)
        ->and($counterparty->ensureWallet()->fresh()->balance)->toBe(CP_START);
});

test('CheckTradeOffer keeps waiting while the initiator has not confirmed', function () {
    Http::fake(['*/trade/offer-state' => Http::response(['ok' => true, 'state' => 9, 'stateName' => 'CreatedNeedsConfirmation'])]);

    ['trade' => $trade] = offerReadyPurchase();

    (new CheckTradeOffer($trade->id))->handle(app(GcClient::class), app(TradeVerifier::class));

    expect($trade->refresh()->status)->toBe(TradeStatus::PendingDelivery)
        ->and($trade->events()->where('type', 'awaiting_confirmation')->exists())->toBeTrue();
});

test('reviewAcceptedTrade reverses when a sent asset returns to its giver', function () {
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = acceptedPurchase();

    // The sold asset reappears in the initiator's inventory (rollback).
    $leg = $trade->items()->first();
    InventoryItem::factory()->for($initiator)->create([
        'item_description_id' => $leg->item_description_id,
        'asset_id' => $leg->asset_id_sent,
    ]);

    app(TradeVerifier::class)->reviewAcceptedTrade($trade, [$initiator->id => true, $counterparty->id => true]);

    expect($trade->refresh()->status)->toBe(TradeStatus::Reversed)
        ->and($initiator->fresh()->isSuspended())->toBeTrue()
        ->and($counterparty->ensureWallet()->fresh()->balance)->toBe(CP_START);

    assertCashConserved($trade);
});

test('reviewAcceptedTrade completes when the window elapsed and nothing returned', function () {
    config()->set('trades.protection_hold_seconds', 0);
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = acceptedPurchase();

    app(TradeVerifier::class)->reviewAcceptedTrade($trade, [$initiator->id => true, $counterparty->id => true]);

    expect($trade->refresh()->status)->toBe(TradeStatus::Completed)
        ->and($initiator->ensureWallet()->fresh()->balance)->toBe(TRADE_PRICE);
});

test('reviewAcceptedTrade will not complete when a receiver read failed', function () {
    config()->set('trades.protection_hold_seconds', 0);
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = acceptedPurchase();

    // Cannot confirm the item did not return -> stay accepted, do not pay out.
    app(TradeVerifier::class)->reviewAcceptedTrade($trade, [$initiator->id => true, $counterparty->id => false]);

    expect($trade->refresh()->status)->toBe(TradeStatus::Accepted);
});

test('VerifyAcceptedTrade reverses from the public inventories with no session', function () {
    ['trade' => $trade, 'initiator' => $initiator, 'counterparty' => $counterparty] = acceptedPurchase();
    $leg = $trade->items()->with('itemDescription')->first();
    $description = $leg->itemDescription;

    // The rolled-back asset reappears in the initiator's public inventory.
    FakeInventoryProvider::set($initiator->steam_id, 'ok', [[
        'asset_id' => $leg->asset_id_sent,
        'class_id' => $description->classid,
        'instance_id' => $description->instanceid,
        'amount' => 1,
        'name' => $description->name,
        'market_name' => $description->market_name,
        'market_hash_name' => $description->market_hash_name,
        'type' => $description->type,
        'tradable' => true,
        'tradable_after' => null,
        'trade_hold_days' => null,
        'marketable' => true,
        'icon_url' => $description->icon_url,
    ]]);
    FakeInventoryProvider::set($counterparty->steam_id, 'ok', []);

    (new VerifyAcceptedTrade($trade->id))->handle(
        app(FakeInventoryProvider::class),
        app(SteamInventorySync::class),
        app(TradeVerifier::class),
    );

    expect($trade->refresh()->status)->toBe(TradeStatus::Reversed)
        ->and($initiator->fresh()->isSuspended())->toBeTrue()
        ->and($counterparty->ensureWallet()->fresh()->balance)->toBe(CP_START);
});

test('the poll command routes pending offers to the offer-state check', function () {
    Queue::fake();

    $trade = offerReadyPurchase()['trade'];
    $trade->forceFill(['next_poll_at' => now()])->save();

    $this->artisan('trades:poll')->assertSuccessful();

    Queue::assertPushed(CheckTradeOffer::class, fn ($job) => $job->tradeId === $trade->id);
});

test('the poll command routes accepted offers to the session-free inventory check', function () {
    Queue::fake();

    $trade = acceptedPurchase()['trade'];
    $trade->forceFill(['next_poll_at' => now()])->save();

    $this->artisan('trades:poll')->assertSuccessful();

    Queue::assertPushed(VerifyAcceptedTrade::class, fn ($job) => $job->tradeId === $trade->id);
});

test('the poll command routes offer-less trades to the inventory job', function () {
    Queue::fake();

    $trade = Trade::factory()->create(['status' => TradeStatus::PendingDelivery, 'next_poll_at' => now(), 'steam_tradeoffer_id' => null]);

    $this->artisan('trades:poll')->assertSuccessful();

    Queue::assertPushed(VerifyTradeInventories::class, fn ($job) => $job->tradeId === $trade->id);
});
