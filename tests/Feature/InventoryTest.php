<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Build a minimal SteamAPIs-shaped inventory payload.
 *
 * @param  array<int, array<string, mixed>>  $ownerDescriptions
 */
function fakeInventoryPayload(bool $tradable = true, array $ownerDescriptions = []): array
{
    return [
        'assets' => [
            ['assetid' => '111', 'classid' => '222', 'instanceid' => '333', 'amount' => '1'],
        ],
        'descriptions' => [
            [
                'classid' => '222',
                'instanceid' => '333',
                'name' => 'AK-47 | Redline',
                'market_name' => 'AK-47 | Redline (Field-Tested)',
                'type' => 'Classified Rifle',
                'tradable' => $tradable ? 1 : 0,
                'marketable' => 1,
                'market_tradable_restriction' => 7,
                'icon_url' => 'abc123',
                'owner_descriptions' => $ownerDescriptions,
            ],
        ],
    ];
}

function steamUser(): User
{
    return User::factory()->create(['steam_id' => '76561198000000000']);
}

test('it returns the mapped inventory for a public profile', function () {
    Http::fake(['*' => Http::response(fakeInventoryPayload())]);

    $this->actingAs(steamUser())
        ->getJson(route('inventory.index'))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.name', 'AK-47 | Redline')
        ->assertJsonPath('items.0.tradable', true)
        ->assertJsonPath('items.0.tradable_after', null)
        ->assertJsonPath('items.0.trade_hold_days', 7)
        ->assertJsonPath('items.0.icon_url', 'https://community.cloudflare.steamstatic.com/economy/image/abc123');
});

test('it parses the exact trade-lock expiry from owner_descriptions', function () {
    Http::fake(['*' => Http::response(fakeInventoryPayload(
        tradable: false,
        ownerDescriptions: [
            ['type' => 'html', 'value' => 'Tradable After 14 Jul 2026 (07:00:00) GMT'],
        ],
    ))]);

    $this->actingAs(steamUser())
        ->getJson(route('inventory.index'))
        ->assertOk()
        ->assertJsonPath('items.0.tradable', false)
        ->assertJsonPath('items.0.tradable_after', '2026-07-14T07:00:00+00:00');
});

test('a locked item without an owner_descriptions date reports a null expiry', function () {
    Http::fake(['*' => Http::response(fakeInventoryPayload(tradable: false))]);

    $this->actingAs(steamUser())
        ->getJson(route('inventory.index'))
        ->assertOk()
        ->assertJsonPath('items.0.tradable', false)
        ->assertJsonPath('items.0.tradable_after', null);
});

test('a private inventory returns a 409 with an actionable code', function () {
    Http::fake(['*' => Http::response(['Error' => 'This profile is private.'], 403)]);

    $this->actingAs(steamUser())
        ->getJson(route('inventory.index'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'inventory_private');
});

test('an upstream failure with no stored items returns a 502 and is not throttled', function () {
    Http::fake(['*' => Http::response('down', 500)]);

    $steamUser = steamUser();

    $this->actingAs($steamUser)
        ->getJson(route('inventory.index'))
        ->assertStatus(502);

    expect(Cache::has("inventory:fetched:{$steamUser->steam_id}:730:2"))->toBeFalse();
});

test('a successful fetch persists items and the description is shared', function () {
    Http::fake(['*' => Http::response(fakeInventoryPayload())]);

    $user = steamUser();

    $this->actingAs($user)->getJson(route('inventory.index'))->assertOk();

    $this->assertDatabaseHas('item_descriptions', [
        'app_id' => 730,
        'classid' => '222',
        'instanceid' => '333',
        'name' => 'AK-47 | Redline',
    ]);
    $this->assertDatabaseHas('inventory_items', [
        'user_id' => $user->id,
        'asset_id' => '111',
        'tradable' => true,
    ]);
});

test('a throttled request serves stored items without refetching from steam', function () {
    Http::fake(['*' => Http::response(fakeInventoryPayload())]);

    $user = steamUser();

    $this->actingAs($user)->getJson(route('inventory.index'))->assertOk();
    $this->actingAs($user)->getJson(route('inventory.index'))->assertOk()->assertJsonPath('count', 1);

    Http::assertSentCount(1);
});

test('items that leave the inventory are pruned on the next fresh fetch', function () {
    $user = steamUser();

    // A later inventory no longer contains asset 111 but has a new asset 999.
    $next = fakeInventoryPayload();
    $next['assets'][0]['assetid'] = '999';

    Http::fakeSequence()
        ->push(fakeInventoryPayload())
        ->push($next);

    $this->actingAs($user)->getJson(route('inventory.index'))->assertOk();

    $this->actingAs($user)
        ->getJson(route('inventory.index', ['fresh' => 1]))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.asset_id', '999');

    $this->assertDatabaseMissing('inventory_items', ['asset_id' => '111']);
});

test('a transient upstream failure falls back to the last known inventory', function () {
    $user = steamUser();

    Http::fakeSequence()
        ->push(fakeInventoryPayload())
        ->push('down', 500);

    $this->actingAs($user)->getJson(route('inventory.index'))->assertOk();

    $this->actingAs($user)
        ->getJson(route('inventory.index', ['fresh' => 1]))
        ->assertOk()
        ->assertJsonPath('stale', true)
        ->assertJsonPath('count', 1)
        ->assertJsonPath('items.0.asset_id', '111');
});

test('a user without a linked steam account is rejected', function () {
    $user = User::factory()->create(['steam_id' => null]);

    $this->actingAs($user)
        ->getJson(route('inventory.index'))
        ->assertStatus(422);
});

test('the inventory route requires authentication', function () {
    $this->getJson(route('inventory.index'))->assertUnauthorized();
});
