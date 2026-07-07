<?php

use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\User;
use App\Services\Trading\TradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A seller who owns one listed, tradable item.
 *
 * @return array{seller: User, item: InventoryItem}
 */
function sellerWithItem(): array
{
    $seller = User::factory()->create(['steam_id' => '76561199000000020']);
    $description = ItemDescription::factory()->create();
    $item = InventoryItem::factory()->for($seller)->create([
        'item_description_id' => $description->id,
        'tradable' => true,
    ]);

    return ['seller' => $seller, 'item' => $item];
}

function buyerWithBalance(int $balance = 10_000): User
{
    $buyer = User::factory()->create([
        'steam_id' => '76561199000000021',
        'trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=abc',
    ]);
    $buyer->ensureWallet()->forceFill(['balance' => $balance])->save();

    return $buyer;
}

test('buying an item holds the buyer funds and creates a pending trade', function () {
    ['item' => $item] = sellerWithItem();
    $buyer = buyerWithBalance();

    $this->actingAs($buyer)
        ->postJson(route('trades.store'), ['inventory_item_id' => $item->id, 'price' => 2_500])
        ->assertCreated()
        ->assertJsonPath('trade.status', 'pending_delivery')
        ->assertJsonPath('trade.price', 2_500);

    expect($buyer->ensureWallet()->fresh()->balance)->toBe(7_500);
    $this->assertDatabaseHas('trades', ['buyer_id' => $buyer->id, 'status' => 'pending_delivery']);
});

test('you cannot buy your own item', function () {
    ['seller' => $seller, 'item' => $item] = sellerWithItem();
    $seller->update(['trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=abc']);
    $seller->ensureWallet()->forceFill(['balance' => 10_000])->save();

    $this->actingAs($seller)
        ->postJson(route('trades.store'), ['inventory_item_id' => $item->id, 'price' => 2_500])
        ->assertStatus(422)
        ->assertJsonPath('code', 'own_item');
});

test('buying without a trade url is rejected', function () {
    ['item' => $item] = sellerWithItem();
    $buyer = buyerWithBalance();
    $buyer->update(['trade_url' => null]);

    $this->actingAs($buyer)
        ->postJson(route('trades.store'), ['inventory_item_id' => $item->id, 'price' => 2_500])
        ->assertStatus(422)
        ->assertJsonPath('code', 'trade_url_required');
});

test('buying with insufficient balance is rejected', function () {
    ['item' => $item] = sellerWithItem();
    $buyer = buyerWithBalance(1_000);

    $this->actingAs($buyer)
        ->postJson(route('trades.store'), ['inventory_item_id' => $item->id, 'price' => 2_500])
        ->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_funds');

    expect($buyer->ensureWallet()->fresh()->balance)->toBe(1_000);
});

test('buying from a suspended seller is rejected', function () {
    ['seller' => $seller, 'item' => $item] = sellerWithItem();
    $seller->forceFill(['suspended_at' => now(), 'suspension_reason' => 'test'])->save();
    $buyer = buyerWithBalance();

    $this->actingAs($buyer)
        ->postJson(route('trades.store'), ['inventory_item_id' => $item->id, 'price' => 2_500])
        ->assertStatus(422)
        ->assertJsonPath('code', 'seller_suspended');
});

test('a suspended buyer is blocked from trading by middleware', function () {
    ['item' => $item] = sellerWithItem();
    $buyer = buyerWithBalance();
    $buyer->forceFill(['suspended_at' => now(), 'suspension_reason' => 'test'])->save();

    $this->actingAs($buyer)
        ->postJson(route('trades.store'), ['inventory_item_id' => $item->id, 'price' => 2_500])
        ->assertStatus(403)
        ->assertJsonPath('code', 'account_suspended');
});

test('a party can view their trade but a stranger cannot', function () {
    ['seller' => $seller, 'item' => $item] = sellerWithItem();
    $buyer = buyerWithBalance();
    $trade = app(TradeService::class)->open($buyer, $item, 2_500);

    $this->actingAs($buyer)->getJson(route('trades.show', $trade))->assertOk()->assertJsonPath('trade.id', $trade->id);
    $this->actingAs($seller)->getJson(route('trades.show', $trade))->assertOk();

    $stranger = User::factory()->create(['steam_id' => '76561199000000099']);
    $this->actingAs($stranger)->getJson(route('trades.show', $trade))->assertNotFound();
});

test('creating a trade requires authentication', function () {
    ['item' => $item] = sellerWithItem();

    $this->postJson(route('trades.store'), ['inventory_item_id' => $item->id, 'price' => 2_500])
        ->assertUnauthorized();
});
