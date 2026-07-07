<?php

use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A seller who owns one tradable item.
 *
 * @return array{seller: User, item: InventoryItem}
 */
function sellerOwning(bool $tradable = true): array
{
    $seller = User::factory()->create(['steam_id' => fake()->unique()->numerify('7656119#########')]);
    $description = ItemDescription::factory()->create();
    $item = InventoryItem::factory()->for($seller)->create([
        'item_description_id' => $description->id,
        'tradable' => $tradable,
    ]);

    return ['seller' => $seller, 'item' => $item];
}

function funded(int $balance = 10_000): User
{
    $buyer = User::factory()->create([
        'steam_id' => fake()->unique()->numerify('7656119#########'),
        'trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=abc',
    ]);
    $buyer->ensureWallet()->forceFill(['balance' => $balance])->save();

    return $buyer;
}

test('the marketplace lists active listings', function () {
    ['item' => $item] = sellerOwning();
    Listing::factory()->forItem($item)->create(['price' => 2_500]);

    $this->actingAs(funded())
        ->getJson(route('listings.index'))
        ->assertOk()
        ->assertJsonPath('listings.0.price', 2_500)
        ->assertJsonPath('listings.0.status', 'active');
});

test('a seller can list a tradable item', function () {
    ['seller' => $seller, 'item' => $item] = sellerOwning();

    $this->actingAs($seller)
        ->postJson(route('listings.store'), ['inventory_item_id' => $item->id, 'price' => 3_000])
        ->assertCreated()
        ->assertJsonPath('listing.price', 3_000);

    $this->assertDatabaseHas('listings', ['seller_id' => $seller->id, 'asset_id' => $item->asset_id, 'status' => 'active']);
});

test('a trade-locked item cannot be listed', function () {
    ['seller' => $seller, 'item' => $item] = sellerOwning(tradable: false);

    $this->actingAs($seller)
        ->postJson(route('listings.store'), ['inventory_item_id' => $item->id, 'price' => 3_000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'item_locked');
});

test('an item cannot be listed twice', function () {
    ['seller' => $seller, 'item' => $item] = sellerOwning();
    Listing::factory()->forItem($item)->create();

    $this->actingAs($seller)
        ->postJson(route('listings.store'), ['inventory_item_id' => $item->id, 'price' => 3_000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'already_listed');
});

test('a seller can cancel their own listing', function () {
    ['seller' => $seller, 'item' => $item] = sellerOwning();
    $listing = Listing::factory()->forItem($item)->create();

    $this->actingAs($seller)->deleteJson(route('listings.destroy', $listing))->assertOk();

    $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'cancelled']);
});

test('buying a listing holds funds, opens a trade, and marks it sold', function () {
    ['item' => $item] = sellerOwning();
    $listing = Listing::factory()->forItem($item)->create(['price' => 2_500]);
    $buyer = funded();

    $this->actingAs($buyer)
        ->postJson(route('listings.purchase', $listing))
        ->assertCreated()
        ->assertJsonPath('trade.status', 'pending_delivery')
        ->assertJsonPath('trade.price', 2_500);

    expect($buyer->ensureWallet()->fresh()->balance)->toBe(7_500);
    $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'sold']);
    $this->assertDatabaseHas('trades', ['buyer_id' => $buyer->id, 'seller_id' => $listing->seller_id]);
});

test('you cannot buy your own listing', function () {
    ['seller' => $seller, 'item' => $item] = sellerOwning();
    $seller->update(['trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=abc']);
    $seller->ensureWallet()->forceFill(['balance' => 10_000])->save();
    $listing = Listing::factory()->forItem($item)->create();

    $this->actingAs($seller)
        ->postJson(route('listings.purchase', $listing))
        ->assertStatus(422)
        ->assertJsonPath('code', 'own_listing');
});

test('buying without a trade url is rejected', function () {
    ['item' => $item] = sellerOwning();
    $listing = Listing::factory()->forItem($item)->create();
    $buyer = funded();
    $buyer->update(['trade_url' => null]);

    $this->actingAs($buyer)
        ->postJson(route('listings.purchase', $listing))
        ->assertStatus(422)
        ->assertJsonPath('code', 'trade_url_required');
});

test('buying with too little balance is rejected', function () {
    ['item' => $item] = sellerOwning();
    $listing = Listing::factory()->forItem($item)->create(['price' => 50_000]);
    $buyer = funded(1_000);

    $this->actingAs($buyer)
        ->postJson(route('listings.purchase', $listing))
        ->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_funds');
});

test('a sold listing cannot be bought again', function () {
    ['item' => $item] = sellerOwning();
    $listing = Listing::factory()->forItem($item)->create(['status' => 'sold']);

    $this->actingAs(funded())
        ->postJson(route('listings.purchase', $listing))
        ->assertStatus(409)
        ->assertJsonPath('code', 'unavailable');
});

test('the wallet endpoint returns the balance', function () {
    $buyer = funded(4_200);

    $this->actingAs($buyer)
        ->getJson(route('wallet.show'))
        ->assertOk()
        ->assertJsonPath('balance', 4_200);
});

test('a user can save a valid steam trade url', function () {
    $user = User::factory()->create(['steam_id' => '76561199000000050']);

    $this->actingAs($user)
        ->putJson(route('user.trade-url'), ['trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=123&token=AbC-9'])
        ->assertOk();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=123&token=AbC-9']);
});

test('an invalid trade url is rejected', function () {
    $user = User::factory()->create(['steam_id' => '76561199000000051']);

    $this->actingAs($user)
        ->putJson(route('user.trade-url'), ['trade_url' => 'https://example.com/not-steam'])
        ->assertStatus(422);
});
