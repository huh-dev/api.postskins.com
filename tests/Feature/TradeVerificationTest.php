<?php

use App\Enums\TradeStatus;
use App\Jobs\VerifyBuyerInventory;
use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\Trade;
use App\Models\User;
use App\Services\Steam\FakeInventoryProvider;
use App\Services\SteamInventorySync;
use App\Services\Trading\TradeService;
use App\Services\Trading\TradeVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const BUYER_START = 10_000;
const TRADE_PRICE = 2_500;

/**
 * Seed a purchased (pending-delivery) trade with funds held, and return the
 * trade plus its parties and the shared item description.
 *
 * @return array{trade: Trade, seller: User, buyer: User, description: ItemDescription}
 */
function purchasedTrade(): array
{
    $description = ItemDescription::factory()->create([
        'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
    ]);

    $seller = User::factory()->create(['steam_id' => '76561199000000010']);
    $buyer = User::factory()->create([
        'steam_id' => '76561199000000011',
        'trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=abc',
    ]);
    $buyer->ensureWallet()->forceFill(['balance' => BUYER_START])->save();

    $listing = InventoryItem::factory()->for($seller)->create([
        'item_description_id' => $description->id,
        'tradable' => true,
    ]);

    $trade = app(TradeService::class)->open($buyer, $listing, TRADE_PRICE);

    return ['trade' => $trade, 'seller' => $seller, 'buyer' => $buyer, 'description' => $description];
}

/**
 * Simulate an item arriving (trade-locked) in the buyer's inventory.
 */
function deliver(User $buyer, ItemDescription $description, string $assetId = 'rx-1'): InventoryItem
{
    return InventoryItem::create([
        'user_id' => $buyer->id,
        'steam_id' => $buyer->steam_id,
        'app_id' => 730,
        'context_id' => 2,
        'asset_id' => $assetId,
        'item_description_id' => $description->id,
        'amount' => 1,
        'tradable' => false,
        'tradable_after' => now()->addDays(7),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

/**
 * Total money in the system should always equal the buyer's starting balance,
 * since the seller starts at zero.
 */
function assertMoneyConserved(User $seller, User $buyer): void
{
    $sellerWallet = $seller->ensureWallet()->fresh();
    $buyerWallet = $buyer->ensureWallet()->fresh();

    expect($buyerWallet->balance + $sellerWallet->balance + $sellerWallet->locked_balance)->toBe(BUYER_START);
}

test('holding the buyer funds happens at purchase time', function () {
    ['buyer' => $buyer] = purchasedTrade();

    expect($buyer->ensureWallet()->fresh()->balance)->toBe(BUYER_START - TRADE_PRICE);
});

test('delivery is detected and the seller payout is locked', function () {
    ['trade' => $trade, 'seller' => $seller, 'buyer' => $buyer, 'description' => $description] = purchasedTrade();

    deliver($buyer, $description);
    app(TradeVerifier::class)->verify($trade);

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Accepted)
        ->and($trade->asset_id_received)->toBe('rx-1')
        ->and($seller->ensureWallet()->fresh()->locked_balance)->toBe(TRADE_PRICE)
        ->and($seller->ensureWallet()->fresh()->balance)->toBe(0);

    assertMoneyConserved($seller, $buyer);
});

test('the payout is released after the protection window passes', function () {
    config()->set('trades.protection_hold_seconds', 0);

    ['trade' => $trade, 'seller' => $seller, 'buyer' => $buyer, 'description' => $description] = purchasedTrade();

    deliver($buyer, $description);
    app(TradeVerifier::class)->verify($trade);

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Completed)
        ->and($seller->ensureWallet()->fresh()->balance)->toBe(TRADE_PRICE)
        ->and($seller->ensureWallet()->fresh()->locked_balance)->toBe(0);

    assertMoneyConserved($seller, $buyer);
});

test('a reversal suspends the seller, refunds the buyer, and voids the payout', function () {
    ['trade' => $trade, 'seller' => $seller, 'buyer' => $buyer, 'description' => $description] = purchasedTrade();

    // Accept first (7-day window keeps it in the accepted state).
    $received = deliver($buyer, $description);
    app(TradeVerifier::class)->verify($trade);
    expect($trade->refresh()->status)->toBe(TradeStatus::Accepted);

    // The item leaves the buyer's inventory during the window -> reversal.
    $received->delete();
    app(TradeVerifier::class)->verify($trade);

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Reversed)
        ->and($seller->fresh()->isSuspended())->toBeTrue()
        ->and($seller->ensureWallet()->fresh()->locked_balance)->toBe(0)
        ->and($seller->ensureWallet()->fresh()->balance)->toBe(0)
        ->and($buyer->ensureWallet()->fresh()->balance)->toBe(BUYER_START);

    expect($trade->events()->where('type', 'reversal')->exists())->toBeTrue();
    assertMoneyConserved($seller, $buyer);
});

test('a wrong item is held for manual review as a dispute', function () {
    ['trade' => $trade, 'seller' => $seller, 'buyer' => $buyer] = purchasedTrade();

    // A different (locked) item arrives instead of the one that was bought.
    $other = ItemDescription::factory()->create(['market_hash_name' => 'Glock-18 | Fade (Factory New)']);
    deliver($buyer, $other, 'rx-wrong');

    app(TradeVerifier::class)->verify($trade);

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Disputed)
        // Buyer stays debited, seller stays uncredited, pending review.
        ->and($buyer->ensureWallet()->fresh()->balance)->toBe(BUYER_START - TRADE_PRICE)
        ->and($seller->ensureWallet()->fresh()->balance)->toBe(0)
        ->and($seller->ensureWallet()->fresh()->locked_balance)->toBe(0);
});

test('an undelivered trade is cancelled and refunded after the escrow window', function () {
    ['trade' => $trade, 'seller' => $seller, 'buyer' => $buyer] = purchasedTrade();

    $this->travel(16)->days();
    app(TradeVerifier::class)->verify($trade);
    $this->travelBack();

    $trade->refresh();
    expect($trade->status)->toBe(TradeStatus::Cancelled)
        ->and($buyer->ensureWallet()->fresh()->balance)->toBe(BUYER_START);

    assertMoneyConserved($seller, $buyer);
});

test('a failed inventory read never advances a trade', function () {
    ['trade' => $trade, 'seller' => $seller, 'buyer' => $buyer] = purchasedTrade();

    FakeInventoryProvider::set($buyer->steam_id, 'error');

    (new VerifyBuyerInventory($buyer->id))->handle(
        app(FakeInventoryProvider::class),
        app(SteamInventorySync::class),
        app(TradeVerifier::class),
    );

    expect($trade->refresh()->status)->toBe(TradeStatus::PendingDelivery)
        ->and($seller->ensureWallet()->fresh()->locked_balance)->toBe(0);
});

test('the verification job accepts a trade from a successful fake read', function () {
    ['trade' => $trade, 'buyer' => $buyer, 'description' => $description] = purchasedTrade();

    FakeInventoryProvider::set($buyer->steam_id, 'ok', [[
        'asset_id' => 'rx-job',
        'class_id' => $description->classid,
        'instance_id' => $description->instanceid,
        'amount' => 1,
        'name' => $description->name,
        'market_name' => $description->market_name,
        'market_hash_name' => $description->market_hash_name,
        'type' => $description->type,
        'tradable' => false,
        'tradable_after' => now()->addDays(7),
        'trade_hold_days' => 7,
        'marketable' => true,
        'icon_url' => $description->icon_url,
    ]]);

    (new VerifyBuyerInventory($buyer->id))->handle(
        app(FakeInventoryProvider::class),
        app(SteamInventorySync::class),
        app(TradeVerifier::class),
    );

    expect($trade->refresh()->status)->toBe(TradeStatus::Accepted)
        ->and($trade->asset_id_received)->toBe('rx-job');
});

test('the poll command dispatches a verification job per buyer with due trades', function () {
    Queue::fake();

    $trade = Trade::factory()->create(['status' => TradeStatus::PendingDelivery, 'next_poll_at' => now()]);

    $this->artisan('trades:poll')->assertSuccessful();

    Queue::assertPushed(VerifyBuyerInventory::class, fn ($job) => $job->buyerId === $trade->buyer_id);
});
