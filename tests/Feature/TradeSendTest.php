<?php

use App\Enums\TradeStatus;
use App\Jobs\SendTradeOffer;
use App\Models\Trade;
use App\Models\User;
use App\Services\Trading\GcClient;
use App\Services\Trading\TradeVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('the send-offer job sends both sides of the swap and records the offer id', function () {
    Http::fake(['*/trade/send-offer' => Http::response(['ok' => true, 'tradeOfferId' => '55443322', 'state' => 'needs_confirmation'])]);

    $initiator = connectedUser('76561199000000100');
    $counterparty = tradeUrlUser('76561199000000101');
    $initiatorItem = ownedItem($initiator, 'AWP | Dragon Lore (Factory New)');
    $counterpartyItem = ownedItem($counterparty, 'M4A4 | Howl (Minimal Wear)');

    $trade = Trade::factory()
        ->between($initiator, $counterparty)
        ->fromInitiator($initiatorItem)
        ->fromCounterparty($counterpartyItem)
        ->create(['status' => TradeStatus::PendingDelivery]);

    (new SendTradeOffer($trade->id))->handle(app(GcClient::class), app(TradeVerifier::class));

    expect($trade->fresh()->steam_tradeoffer_id)->toBe('55443322')
        ->and($trade->events()->where('type', 'offer_sent')->exists())->toBeTrue();

    // The GC request carried the initiator token, counterparty trade URL, and both item lists.
    Http::assertSent(function ($request) use ($counterparty, $initiatorItem, $counterpartyItem) {
        return str_ends_with($request->url(), '/trade/send-offer')
            && $request['refresh_token'] === 'token-76561199000000100'
            && $request['trade_url'] === $counterparty->trade_url
            && collect($request['my_items'])->pluck('assetid')->contains($initiatorItem->asset_id)
            && collect($request['their_items'])->pluck('assetid')->contains($counterpartyItem->asset_id)
            && $request->hasHeader('x-gc-secret');
    });
});

test('the send-offer job cancels the trade when the initiator is not connected', function () {
    $initiator = User::factory()->create(['steam_id' => '76561199000000102']);
    $counterparty = tradeUrlUser('76561199000000103');
    $item = ownedItem($initiator, 'AWP | Dragon Lore (Factory New)');

    $trade = Trade::factory()
        ->between($initiator, $counterparty)
        ->fromInitiator($item)
        ->create(['status' => TradeStatus::PendingDelivery]);

    Http::fake();
    (new SendTradeOffer($trade->id))->handle(app(GcClient::class), app(TradeVerifier::class));

    Http::assertNothingSent();
    expect($trade->fresh()->status)->toBe(TradeStatus::Cancelled)
        ->and($trade->events()->where('type', 'offer_send_failed')->exists())->toBeTrue();
});

test('a seller can start a connect authorization', function () {
    Http::fake(['*/auth/qr/start' => Http::response(['id' => 'sess-1', 'qrUrl' => 'https://s.team/q/xyz'])]);

    $seller = User::factory()->create(['steam_id' => '76561199000000104']);

    $this->actingAs($seller)
        ->postJson(route('seller.connect'))
        ->assertOk()
        ->assertJsonPath('id', 'sess-1')
        ->assertJsonPath('qr_url', 'https://s.team/q/xyz');
});

test('an authorized QR stores the seller refresh token for their own account', function () {
    $seller = User::factory()->create(['steam_id' => '76561199000000105']);

    Http::fake(['*/auth/qr/*' => Http::response([
        'status' => 'authenticated',
        'refreshToken' => 'freshly-minted-token',
        'steamId' => '76561199000000105',
    ])]);

    $this->actingAs($seller)
        ->getJson(route('seller.connect.status', 'sess-1'))
        ->assertOk()
        ->assertJsonPath('status', 'connected');

    $seller->refresh();
    expect($seller->isSellingConnected())->toBeTrue()
        ->and($seller->steam_refresh_token)->toBe('freshly-minted-token');
});

test('a QR authorizing a different steam account is rejected', function () {
    $seller = User::factory()->create(['steam_id' => '76561199000000106']);

    Http::fake(['*/auth/qr/*' => Http::response([
        'status' => 'authenticated',
        'refreshToken' => 'someone-elses-token',
        'steamId' => '76561199000009999',
    ])]);

    $this->actingAs($seller)
        ->getJson(route('seller.connect.status', 'sess-1'))
        ->assertStatus(422)
        ->assertJsonPath('status', 'wrong_account');

    expect($seller->fresh()->isSellingConnected())->toBeFalse();
});

test('the seller status endpoint reflects connection', function () {
    $connected = User::factory()->create(['steam_id' => '76561199000000107', 'steam_refresh_token' => 'tok', 'steam_selling_connected_at' => now()]);

    $this->actingAs($connected)->getJson(route('seller.status'))->assertOk()->assertJsonPath('connected', true);

    $notConnected = User::factory()->create(['steam_id' => '76561199000000108']);
    $this->actingAs($notConnected)->getJson(route('seller.status'))->assertOk()->assertJsonPath('connected', false);
});
