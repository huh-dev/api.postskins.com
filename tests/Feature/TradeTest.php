<?php

use App\Enums\TradeOfferStatus;
use App\Enums\TradePostStatus;
use App\Jobs\SendTradeOffer;
use App\Models\ItemDescription;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\Models\TradePost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('creating a post requires a connected Steam account', function () {
    $user = tradeUrlUser('76561199000000200');
    $item = ownedItem($user, 'AK-47 | Redline (Field-Tested)');

    $this->actingAs($user)
        ->postJson(route('posts.store'), [
            'offering' => [['inventory_item_id' => $item->id]],
            'want_cash' => 5000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'connect_steam_required');
});

test('a connected user can create a trade post', function () {
    $owner = connectedUser('76561199000000201');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $wanted = ItemDescription::factory()->create(['market_hash_name' => 'AK-47 | Redline (Field-Tested)']);

    $this->actingAs($owner)
        ->postJson(route('posts.store'), [
            'offering' => [['inventory_item_id' => $item->id]],
            'wanting' => [['item_description_id' => $wanted->id]],
            'offer_cash' => 0,
            'want_cash' => 5000,
        ])
        ->assertCreated()
        ->assertJsonPath('post.status', 'open')
        ->assertJsonPath('post.want_cash', 5000)
        ->assertJsonCount(1, 'post.offering')
        ->assertJsonCount(1, 'post.wanting');
});

test('a post cannot offer an item the user does not own', function () {
    $owner = connectedUser('76561199000000202');
    $stranger = tradeUrlUser('76561199000000203');
    $othersItem = ownedItem($stranger, 'AK-47 | Redline (Field-Tested)');

    $this->actingAs($owner)
        ->postJson(route('posts.store'), [
            'offering' => [['inventory_item_id' => $othersItem->id]],
            'want_cash' => 5000,
        ])
        ->assertStatus(422);
});

test('submitting an offer requires a trade URL', function () {
    $owner = connectedUser('76561199000000210');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $post = TradePost::factory()->offering($item)->sellingFor(5000)->create();

    $offerer = User::factory()->create(['steam_id' => '76561199000000211', 'trade_url' => null]);

    $this->actingAs($offerer)
        ->postJson(route('posts.offers.store', $post), ['cash_amount' => 5000, 'cash_payer' => 'offerer'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'trade_url_required');
});

test('a user cannot offer on their own post', function () {
    $owner = connectedUser('76561199000000212');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $post = TradePost::factory()->offering($item)->sellingFor(5000)->create();

    $this->actingAs($owner)
        ->postJson(route('posts.offers.store', $post), ['cash_amount' => 5000, 'cash_payer' => 'offerer'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'own_post');
});

test('a cash offer is created against a post', function () {
    $owner = connectedUser('76561199000000213');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $post = TradePost::factory()->offering($item)->sellingFor(5000)->create();

    $offerer = tradeUrlUser('76561199000000214', 5000);

    $this->actingAs($offerer)
        ->postJson(route('posts.offers.store', $post), ['cash_amount' => 5000, 'cash_payer' => 'offerer'])
        ->assertCreated()
        ->assertJsonPath('offer.status', 'pending')
        ->assertJsonPath('offer.cash_amount', 5000);
});

test('accepting an offer executes the trade and sends the Steam offer', function () {
    Queue::fake();

    $owner = connectedUser('76561199000000220');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $post = TradePost::factory()->offering($item)->sellingFor(5000)->create();

    $offerer = tradeUrlUser('76561199000000221', 5000);
    $offer = TradeOffer::factory()->on($post)->payingCash(5000)->create(['offerer_id' => $offerer->id]);

    $this->actingAs($owner)
        ->postJson(route('offers.accept', $offer))
        ->assertCreated()
        ->assertJsonPath('trade.status', 'pending_delivery');

    expect($post->fresh()->status)->toBe(TradePostStatus::Fulfilled)
        ->and($offer->fresh()->status)->toBe(TradeOfferStatus::Accepted);

    Queue::assertPushed(SendTradeOffer::class);
});

test('accepting one offer declines the siblings on the same post', function () {
    Queue::fake();

    $owner = connectedUser('76561199000000230');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $post = TradePost::factory()->offering($item)->sellingFor(5000)->create();

    $a = tradeUrlUser('76561199000000231', 5000);
    $b = tradeUrlUser('76561199000000232', 5000);
    $offerA = TradeOffer::factory()->on($post)->payingCash(5000)->create(['offerer_id' => $a->id]);
    $offerB = TradeOffer::factory()->on($post)->payingCash(5000)->create(['offerer_id' => $b->id]);

    $this->actingAs($owner)->postJson(route('offers.accept', $offerA))->assertCreated();

    expect($offerB->fresh()->status)->toBe(TradeOfferStatus::Declined);
});

test('a second accept on the same post is rejected and makes only one trade', function () {
    Queue::fake();

    $owner = connectedUser('76561199000000240');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $post = TradePost::factory()->offering($item)->sellingFor(5000)->create();

    $a = tradeUrlUser('76561199000000241', 5000);
    $b = tradeUrlUser('76561199000000242', 5000);
    $offerA = TradeOffer::factory()->on($post)->payingCash(5000)->create(['offerer_id' => $a->id]);
    $offerB = TradeOffer::factory()->on($post)->payingCash(5000)->create(['offerer_id' => $b->id]);

    $this->actingAs($owner)->postJson(route('offers.accept', $offerA))->assertCreated();
    $this->actingAs($owner)->postJson(route('offers.accept', $offerB))
        ->assertStatus(409)
        ->assertJsonPath('code', 'post_unavailable');

    expect(Trade::count())->toBe(1);
});

test('accepting fails when the paying party has too little balance', function () {
    Queue::fake();

    $owner = connectedUser('76561199000000250');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $post = TradePost::factory()->offering($item)->sellingFor(5000)->create();

    $offerer = tradeUrlUser('76561199000000251', 1000); // not enough
    $offer = TradeOffer::factory()->on($post)->payingCash(5000)->create(['offerer_id' => $offerer->id]);

    $this->actingAs($owner)
        ->postJson(route('offers.accept', $offer))
        ->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_funds');

    expect($post->fresh()->status)->toBe(TradePostStatus::Open)
        ->and(Trade::count())->toBe(0);
});

test('only a party can view a trade', function () {
    $trade = Trade::factory()->create();
    $stranger = User::factory()->create(['steam_id' => '76561199000000260']);

    $this->actingAs($stranger)->getJson(route('trades.show', $trade))->assertStatus(404);

    $this->actingAs(User::find($trade->initiator_id))
        ->getJson(route('trades.show', $trade))
        ->assertOk()
        ->assertJsonPath('trade.id', $trade->id);
});

test('viewing the market feed lists open posts', function () {
    $owner = connectedUser('76561199000000270');
    $item = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    TradePost::factory()->offering($item)->sellingFor(5000)->create();

    $viewer = tradeUrlUser('76561199000000271');

    $this->actingAs($viewer)
        ->getJson(route('posts.index'))
        ->assertOk()
        ->assertJsonCount(1, 'posts');
});

test('the posts endpoint requires authentication', function () {
    $this->getJson(route('posts.index'))->assertUnauthorized();
});
