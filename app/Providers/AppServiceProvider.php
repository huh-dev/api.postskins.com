<?php

namespace App\Providers;

use App\Services\Steam\FakeInventoryProvider;
use App\Services\Steam\InventoryProvider;
use App\Services\Steam\OfficialSteamInventoryProvider;
use App\Services\Steam\SteamApisInventoryProvider;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Steam\Provider as SteamProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        AliasLoader::getInstance()->alias('Socialite', Socialite::class);

        $this->app->bind(InventoryProvider::class, function ($app): InventoryProvider {
            // The trade-lab harness drives everything through an in-memory fake.
            if (config('trades.fake_steam')) {
                return $app->make(FakeInventoryProvider::class);
            }

            return match (config('services.steam_inventory.driver')) {
                'official' => $app->make(OfficialSteamInventoryProvider::class),
                default => $app->make(SteamApisInventoryProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('steam', SteamProvider::class);
        });
    }
}
