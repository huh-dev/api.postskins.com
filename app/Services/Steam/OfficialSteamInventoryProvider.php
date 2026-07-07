<?php

namespace App\Services\Steam;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches inventories directly from the public Steam Community endpoint.
 *
 * Free and key-less, but rate-limited per IP — suitable for local development
 * or low volume. Large inventories are paged via `more_items` + `last_assetid`.
 */
class OfficialSteamInventoryProvider extends AbstractSteamInventoryProvider
{
    private const BASE_URL = 'https://steamcommunity.com';

    /**
     * Steam's maximum items per inventory page.
     */
    private const PAGE_SIZE = 5000;

    /**
     * Safety bound so a paging bug can never loop indefinitely (25k items).
     */
    private const MAX_PAGES = 5;

    public function fetch(string $steamId, int $appId, int $contextId): InventoryResult
    {
        $assets = [];
        $descriptions = [];
        $startAssetId = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            try {
                $response = Http::acceptJson()->timeout(15)->get(
                    self::BASE_URL."/inventory/{$steamId}/{$appId}/{$contextId}",
                    array_filter([
                        'l' => 'english',
                        'count' => self::PAGE_SIZE,
                        'start_assetid' => $startAssetId,
                    ]),
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

            $assets = array_merge($assets, $response->json('assets', []));

            // Descriptions repeat across pages; key them so duplicates collapse.
            foreach ($response->json('descriptions', []) as $description) {
                $descriptions[$description['classid'].'_'.($description['instanceid'] ?? '0')] = $description;
            }

            if (! $response->json('more_items') || ! ($startAssetId = $response->json('last_assetid'))) {
                break;
            }
        }

        return InventoryResult::ok($this->mapItems($assets, array_values($descriptions)));
    }
}
