<?php

namespace App\Services\Steam;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches inventories through the SteamAPIs aggregator, which fronts Steam with
 * a proxy pool and returns the whole inventory in a single response.
 */
class SteamApisInventoryProvider extends AbstractSteamInventoryProvider
{
    public function fetch(string $steamId, int $appId, int $contextId): InventoryResult
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.steamapis.key'),
            ])->acceptJson()->timeout(15)->get(
                config('services.steamapis.base_url')."/steam/users/{$steamId}/inventory/{$appId}/{$contextId}"
            );
        } catch (ConnectionException) {
            return InventoryResult::error();
        }

        if ($this->indicatesPrivateInventory($response)) {
            return InventoryResult::privateInventory();
        }

        if ($response->failed()) {
            return InventoryResult::error();
        }

        return InventoryResult::ok(
            $this->mapItems($response->json('assets', []), $response->json('descriptions', []))
        );
    }
}
