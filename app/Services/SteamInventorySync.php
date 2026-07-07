<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SteamInventorySync
{
    /**
     * Persist a freshly fetched inventory and return the current stored items.
     *
     * Descriptions are deduplicated into the shared `item_descriptions` table;
     * assets are upserted into `inventory_items`; assets no longer present are
     * pruned. Known `tradable_after` and `first_seen_at` values are preserved
     * across syncs so trade-lock data is never lost to an incomplete refetch.
     *
     * @param  array<int, array<string, mixed>>  $items  Parsed items from the fetch.
     * @return Collection<int, InventoryItem>
     */
    public function sync(User $user, int $appId, int $contextId, array $items): Collection
    {
        $now = Carbon::now();

        DB::transaction(function () use ($user, $appId, $contextId, $items, $now): void {
            $this->upsertDescriptions($appId, $items);
            $descriptionIds = $this->descriptionIdMap($appId, $items);
            $this->upsertItems($user, $appId, $contextId, $items, $descriptionIds, $now);
            $this->pruneMissing($user, $appId, $contextId, $items);
        });

        return InventoryItem::with('itemDescription')
            ->where('user_id', $user->id)
            ->where('app_id', $appId)
            ->where('context_id', $contextId)
            ->orderByDesc('tradable')
            ->orderBy('id')
            ->get();
    }

    /**
     * Upsert the shared metadata rows for every distinct item in the payload.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function upsertDescriptions(int $appId, array $items): void
    {
        $rows = collect($items)
            ->unique(fn (array $item): string => $this->descriptionKey($item))
            ->map(fn (array $item): array => [
                'app_id' => $appId,
                'classid' => (string) $item['class_id'],
                'instanceid' => (string) ($item['instance_id'] ?? '0'),
                'name' => $item['name'],
                'market_name' => $item['market_name'],
                'market_hash_name' => $item['market_hash_name'],
                'type' => $item['type'],
                'icon_url' => $item['icon_url'],
                'marketable' => $item['marketable'],
                'trade_hold_days' => $item['trade_hold_days'],
            ])
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        ItemDescription::upsert(
            $rows,
            ['app_id', 'classid', 'instanceid'],
            ['name', 'market_name', 'market_hash_name', 'type', 'icon_url', 'marketable', 'trade_hold_days'],
        );
    }

    /**
     * Map each "classid_instanceid" key to its persisted description id.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<string, int>
     */
    private function descriptionIdMap(int $appId, array $items): Collection
    {
        $classIds = collect($items)->pluck('class_id')->filter()->unique()->values()->all();

        return ItemDescription::where('app_id', $appId)
            ->whereIn('classid', $classIds)
            ->get(['id', 'classid', 'instanceid'])
            ->keyBy(fn (ItemDescription $description): string => $description->classid.'_'.$description->instanceid)
            ->map->id;
    }

    /**
     * Upsert the owned assets, preserving prior trade-lock and first-seen data.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  Collection<string, int>  $descriptionIds
     */
    private function upsertItems(User $user, int $appId, int $contextId, array $items, Collection $descriptionIds, CarbonInterface $now): void
    {
        $existing = InventoryItem::where('app_id', $appId)
            ->whereIn('asset_id', collect($items)->pluck('asset_id')->map(fn ($id): string => (string) $id)->all())
            ->get()
            ->keyBy('asset_id');

        $rows = collect($items)
            ->map(function (array $item) use ($user, $appId, $contextId, $descriptionIds, $existing, $now): ?array {
                $descriptionId = $descriptionIds->get($this->descriptionKey($item));

                if ($descriptionId === null) {
                    return null;
                }

                $assetId = (string) $item['asset_id'];
                $prior = $existing->get($assetId);
                $tradableAfter = $item['tradable_after'] ?? $prior?->tradable_after;

                return [
                    'user_id' => $user->id,
                    'steam_id' => $user->steam_id,
                    'app_id' => $appId,
                    'context_id' => $contextId,
                    'asset_id' => $assetId,
                    'item_description_id' => $descriptionId,
                    'amount' => $item['amount'],
                    'tradable' => $item['tradable'],
                    'tradable_after' => $tradableAfter?->toDateTimeString(),
                    'first_seen_at' => ($prior?->first_seen_at ?? $now)->toDateTimeString(),
                    'last_seen_at' => $now->toDateTimeString(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        InventoryItem::upsert(
            $rows,
            ['app_id', 'asset_id'],
            ['user_id', 'steam_id', 'context_id', 'item_description_id', 'amount', 'tradable', 'tradable_after', 'last_seen_at'],
        );
    }

    /**
     * Delete stored assets that are no longer present in the fetched inventory.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function pruneMissing(User $user, int $appId, int $contextId, array $items): void
    {
        $assetIds = collect($items)->pluck('asset_id')->map(fn ($id): string => (string) $id)->all();

        InventoryItem::where('user_id', $user->id)
            ->where('app_id', $appId)
            ->where('context_id', $contextId)
            ->when($assetIds !== [], fn ($query) => $query->whereNotIn('asset_id', $assetIds))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function descriptionKey(array $item): string
    {
        return $item['class_id'].'_'.($item['instance_id'] ?? '0');
    }
}
