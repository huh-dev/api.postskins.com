<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

test('the redirect route sends the user to steam', function () {
    Socialite::shouldReceive('driver->redirect')
        ->andReturn(redirect('https://steamcommunity.com/openid/login'));

    $this->get(route('auth.steam.redirect'))
        ->assertRedirect('https://steamcommunity.com/openid/login');
});

test('a new user is created and logged in on callback', function () {
    Socialite::fake('steam', (new SocialiteUser)->map([
        'id' => '76561198000000000',
        'nickname' => 'GabeN',
        'name' => 'Gabe Newell',
        'avatar' => 'https://avatars.steamstatic.com/gaben.jpg',
    ]));

    $this->get(route('auth.steam.callback'))
        ->assertRedirect(config('services.steam.frontend_url'));

    $this->assertDatabaseHas('users', [
        'steam_id' => '76561198000000000',
        'name' => 'GabeN',
        'avatar' => 'https://avatars.steamstatic.com/gaben.jpg',
    ]);

    $this->assertAuthenticatedAs(User::firstWhere('steam_id', '76561198000000000'));

    // The SPA reads /api/user via the session cookie set during the callback.
    $this->getJson('/api/user')
        ->assertSuccessful()
        ->assertJsonPath('steam_id', '76561198000000000')
        ->assertJsonPath('name', 'GabeN');
});

test('logout ends the session', function () {
    Socialite::fake('steam', (new SocialiteUser)->map([
        'id' => '76561198000000000',
        'nickname' => 'GabeN',
        'name' => 'Gabe Newell',
        'avatar' => 'https://avatars.steamstatic.com/gaben.jpg',
    ]));

    // Sign in, then log out through the real session flow.
    $this->get(route('auth.steam.callback'));
    $this->assertAuthenticated();

    $this->postJson(route('logout'))->assertOk();

    // Clear the cached guard so the next check re-reads the invalidated session.
    $this->app['auth']->forgetGuards();

    $this->assertGuest();
});

test('an existing steam user is updated and reused, not duplicated', function () {
    $existing = User::factory()->create([
        'steam_id' => '76561198000000000',
        'name' => 'Old Name',
        'avatar' => 'https://avatars.steamstatic.com/old.jpg',
    ]);

    Socialite::fake('steam', (new SocialiteUser)->map([
        'id' => '76561198000000000',
        'nickname' => 'New Name',
        'name' => 'Gabe Newell',
        'avatar' => 'https://avatars.steamstatic.com/new.jpg',
    ]));

    $this->get(route('auth.steam.callback'))
        ->assertRedirect(config('services.steam.frontend_url'));

    expect(User::where('steam_id', '76561198000000000')->count())->toBe(1);

    $existing->refresh();
    expect($existing->name)->toBe('New Name')
        ->and($existing->avatar)->toBe('https://avatars.steamstatic.com/new.jpg');
});
