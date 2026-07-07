<?php

namespace App\Services\Steam;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * An in-memory inventory provider for local development and the /trade-lab
 * harness. The lab writes a per-Steam-ID state into the cache; this driver
 * replays it so the whole P2P flow can be exercised without real Steam trades.
 * Never bound in production (guarded by `trades.fake_steam`).
 *
 * Only JSON-safe primitives are stored in the cache — `tradable_after` is kept
 * as an ISO-8601 string and rehydrated to a CarbonImmutable on read, so a
 * serializing cache store (database/file/redis) never has to unserialize a
 * date object.
 */
class FakeInventoryProvider implements InventoryProvider
{
    /**
     * Cache key holding the simulated state for a Steam ID.
     */
    public static function key(string $steamId): string
    {
        return "fake_inventory:{$steamId}";
    }

    /**
     * Overwrite the simulated inventory for a Steam ID.
     *
     * @param  'ok'|'private'|'error'  $status
     * @param  array<int, array<string, mixed>>  $items  Normalized item rows.
     */
    public static function set(string $steamId, string $status = 'ok', array $items = []): void
    {
        $items = array_map(function (array $item): array {
            if (($item['tradable_after'] ?? null) instanceof DateTimeInterface) {
                $item['tradable_after'] = $item['tradable_after']->format(DATE_ATOM);
            }

            return $item;
        }, $items);

        Cache::forever(self::key($steamId), ['status' => $status, 'items' => $items]);
    }

    public function fetch(string $steamId, int $appId, int $contextId): InventoryResult
    {
        $state = Cache::get(self::key($steamId), ['status' => 'ok', 'items' => []]);

        return match ($state['status']) {
            'private' => InventoryResult::privateInventory(),
            'error' => InventoryResult::error(),
            default => InventoryResult::ok($this->rehydrate($state['items'])),
        };
    }

    /**
     * Restore `tradable_after` to a CarbonImmutable for the sync layer.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function rehydrate(array $items): array
    {
        return array_map(function (array $item): array {
            if (! empty($item['tradable_after']) && is_string($item['tradable_after'])) {
                $item['tradable_after'] = CarbonImmutable::parse($item['tradable_after']);
            }

            return $item;
        }, $items);
    }
}
