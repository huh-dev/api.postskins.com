<?php

use App\Enums\TradeOfferStatus;
use App\Models\TradeOffer;
use App\Models\TradePost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a pending offer from $offerer against a fresh post owned by a new seller.
 *
 * @return array{post: TradePost, offer: TradeOffer, ownerId: int}
 */
function offerFrom(User $offerer, string $ownerSteamId): array
{
    $owner = connectedUser($ownerSteamId);
    $ownerItem = ownedItem($owner, 'AWP | Dragon Lore (Factory New)');
    $post = TradePost::factory()->offering($ownerItem)->create();

    $offerItem = ownedItem($offerer, 'AK-47 | Redline (Field-Tested)');
    $offer = TradeOffer::factory()->on($post)->giving($offerItem)->create(['offerer_id' => $offerer->id]);

    return ['post' => $post, 'offer' => $offer, 'ownerId' => $owner->id];
}

test('sent offers requires authentication', function () {
    $this->getJson(route('offers.sent'))->assertUnauthorized();
});

test('received offers requires authentication', function () {
    $this->getJson(route('offers.received'))->assertUnauthorized();
});

test('a user sees their sent offers with the post owner as counterparty', function () {
    $offerer = tradeUrlUser('76561199000000301');
    ['ownerId' => $ownerId, 'offer' => $offer] = offerFrom($offerer, '76561199000000302');

    $this->actingAs($offerer)
        ->getJson(route('offers.sent'))
        ->assertOk()
        ->assertJsonCount(1, 'offers')
        ->assertJsonPath('offers.0.id', $offer->id)
        ->assertJsonPath('offers.0.status', 'pending')
        ->assertJsonPath('offers.0.post.owner.id', $ownerId)
        ->assertJsonCount(1, 'offers.0.giving');
});

test('sent offers include non-pending outcomes so the offerer can track them', function () {
    $offerer = tradeUrlUser('76561199000000303');
    ['offer' => $offer] = offerFrom($offerer, '76561199000000304');
    $offer->update(['status' => TradeOfferStatus::Withdrawn]);

    $this->actingAs($offerer)
        ->getJson(route('offers.sent'))
        ->assertOk()
        ->assertJsonCount(1, 'offers')
        ->assertJsonPath('offers.0.status', 'withdrawn');
});

test('sent offers never leak another user\'s offers', function () {
    $offerer = tradeUrlUser('76561199000000305');
    $other = tradeUrlUser('76561199000000306');
    offerFrom($other, '76561199000000307');

    $this->actingAs($offerer)
        ->getJson(route('offers.sent'))
        ->assertOk()
        ->assertJsonCount(0, 'offers');
});

test('a post owner sees pending offers across all their posts', function () {
    $offerer = tradeUrlUser('76561199000000308');
    ['ownerId' => $ownerId, 'offer' => $offer] = offerFrom($offerer, '76561199000000309');
    $owner = User::find($ownerId);

    $this->actingAs($owner)
        ->getJson(route('offers.received'))
        ->assertOk()
        ->assertJsonCount(1, 'offers')
        ->assertJsonPath('offers.0.id', $offer->id)
        ->assertJsonPath('offers.0.offerer.id', $offerer->id);
});

test('received offers exclude non-pending offers and offers on other owners posts', function () {
    $offerer = tradeUrlUser('76561199000000310');

    // A withdrawn offer on the owner's post must not surface.
    ['ownerId' => $ownerId, 'offer' => $withdrawn] = offerFrom($offerer, '76561199000000311');
    $withdrawn->update(['status' => TradeOfferStatus::Withdrawn]);
    $owner = User::find($ownerId);

    // A pending offer, but on a different owner's post.
    offerFrom($offerer, '76561199000000312');

    $this->actingAs($owner)
        ->getJson(route('offers.received'))
        ->assertOk()
        ->assertJsonCount(0, 'offers');
});
