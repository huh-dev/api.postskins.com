<?php

use App\Http\Controllers\Dev\TradeLabController;
use App\Services\Steam\FakeInventoryProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function labSteamPayload(): array
{
    return [
        'assets' => [
            ['assetid' => '111', 'classid' => '222', 'instanceid' => '0', 'amount' => '1'],
        ],
        'descriptions' => [
            [
                'classid' => '222',
                'instanceid' => '0',
                'name' => 'AK-47 | Redline',
                'market_name' => 'AK-47 | Redline (Field-Tested)',
                'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
                'type' => 'Classified Rifle',
                'tradable' => 1,
                'marketable' => 1,
                'market_tradable_restriction' => 7,
                'icon_url' => 'abc123',
            ],
        ],
    ];
}

test('lab syncs a seller inventory and creates an offer from a chosen item', function () {
    app()->instance('env', 'local');
    Http::fake(['*' => Http::response(labSteamPayload())]);

    $controller = app(TradeLabController::class);

    $controller->reset();

    $synced = $controller->syncSeller(Request::create('/', 'POST', ['steam_id' => '76561198000000000']))->getData(true);
    expect($synced['listings'])->not->toBeEmpty();

    $itemId = $synced['listings'][0]['inventory_item_id'];

    $bought = $controller->buy(Request::create('/', 'POST', [
        'inventory_item_id' => $itemId,
        'price' => 2_500,
    ]))->getData(true);

    expect($bought['trade'])->not->toBeNull()
        ->and($bought['trade']['status'])->toBe('pending_delivery');
});

test('lab demo seller loads a catalog with no external dependency', function () {
    app()->instance('env', 'local');

    $controller = app(TradeLabController::class);
    $controller->reset();

    $demo = $controller->demoSeller()->getData(true);

    expect($demo['listings'])->not->toBeEmpty()
        ->and($demo['seller']['name'])->toBe('Demo Seller');

    $bought = $controller->buy(Request::create('/', 'POST', [
        'inventory_item_id' => $demo['listings'][0]['inventory_item_id'],
        'price' => 2_500,
    ]))->getData(true);

    expect($bought['trade']['status'])->toBe('pending_delivery');
});

test('the fake provider caches only primitives and rehydrates the date on read', function () {
    FakeInventoryProvider::set('76561199000000002', 'ok', [[
        'asset_id' => 'x1',
        'class_id' => '1',
        'instance_id' => '0',
        'amount' => 1,
        'name' => 'AK-47 | Redline',
        'market_name' => 'AK-47 | Redline (Field-Tested)',
        'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
        'type' => 'Rifle',
        'tradable' => false,
        'tradable_after' => CarbonImmutable::now()->addDays(7),
        'trade_hold_days' => 7,
        'marketable' => true,
        'icon_url' => null,
    ]]);

    // Nothing rich is serialized into the cache.
    $raw = Cache::get(FakeInventoryProvider::key('76561199000000002'));
    expect($raw['items'][0]['tradable_after'])->toBeString();

    // But the sync layer still receives a real date object.
    $result = app(FakeInventoryProvider::class)->fetch('76561199000000002', 730, 2);
    expect($result->items[0]['tradable_after'])->toBeInstanceOf(CarbonImmutable::class);
});

test('lab simulate received then reversal moves the trade through its lifecycle', function () {
    app()->instance('env', 'local');

    $controller = app(TradeLabController::class);
    $controller->reset();
    $demo = $controller->demoSeller()->getData(true);
    $controller->buy(Request::create('/', 'POST', [
        'inventory_item_id' => $demo['listings'][0]['inventory_item_id'],
        'price' => 2_500,
    ]));

    $received = $controller->simulateReceived()->getData(true);
    expect($received['trade']['status'])->toBe('accepted')
        ->and($received['seller']['locked_balance'])->toBe(2_500);

    $reversed = $controller->simulateReversal()->getData(true);
    expect($reversed['trade']['status'])->toBe('reversed')
        ->and($reversed['seller']['suspended'])->toBeTrue()
        ->and($reversed['buyer']['balance'])->toBe(100_000);
});
