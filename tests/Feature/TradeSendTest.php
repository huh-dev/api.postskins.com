<?php

use App\Enums\TradeStatus;
use App\Jobs\SendTradeOffer;
use App\Models\Trade;
use App\Models\User;
use App\Services\Trading\GcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('the send-offer job sends via the GC service and records the offer id', function () {
    Http::fake(['*/trade/send-offer' => Http::response(['ok' => true, 'tradeOfferId' => '55443322', 'state' => 'needs_confirmation'])]);

    $seller = User::factory()->create([
        'steam_id' => '76561199000000100',
        'steam_refresh_token' => 'a-real-looking-token',
        'steam_selling_connected_at' => now(),
    ]);
    $buyer = User::factory()->create([
        'steam_id' => '76561199000000101',
        'trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=abc',
    ]);
    $trade = Trade::factory()->create([
        'seller_id' => $seller->id,
        'buyer_id' => $buyer->id,
        'status' => TradeStatus::PendingDelivery,
        'asset_id_listed' => '7788',
    ]);

    (new SendTradeOffer($trade->id))->handle(app(GcClient::class));

    expect($trade->fresh()->steam_tradeoffer_id)->toBe('55443322')
        ->and($trade->events()->where('type', 'offer_sent')->exists())->toBeTrue();

    // The GC request carried the seller token, buyer trade URL, and the item.
    Http::assertSent(function ($request) use ($buyer) {
        return str_ends_with($request->url(), '/trade/send-offer')
            && $request['refresh_token'] === 'a-real-looking-token'
            && $request['trade_url'] === $buyer->trade_url
            && $request['item']['assetid'] === '7788'
            && $request->hasHeader('x-gc-secret');
    });
});

test('the send-offer job does nothing when the seller is not connected', function () {
    $seller = User::factory()->create(['steam_id' => '76561199000000102', 'steam_refresh_token' => null]);
    $buyer = User::factory()->create(['steam_id' => '76561199000000103', 'trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=abc']);
    $trade = Trade::factory()->create(['seller_id' => $seller->id, 'buyer_id' => $buyer->id, 'status' => TradeStatus::PendingDelivery]);

    Http::fake();
    (new SendTradeOffer($trade->id))->handle(app(GcClient::class));

    Http::assertNothingSent();
    expect($trade->fresh()->steam_tradeoffer_id)->toBeNull()
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
