<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\SteamInventorySync;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class InventoryController extends Controller
{
    /**
     * How long to wait before re-fetching the same inventory from Steam.
     */
    private const REFETCH_THROTTLE_MINUTES = 5;

    public function __construct(private readonly SteamInventorySync $sync) {}

    /**
     * Return the authenticated user's Steam inventory for the given app/context.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app_id' => ['sometimes', 'integer', 'min:1'],
            'context_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $appId = $validated['app_id'] ?? 730;      // Counter-Strike 2
        $contextId = $validated['context_id'] ?? 2;
        $user = $request->user();

        if (! $user->steam_id) {
            return response()->json(['message' => 'No Steam account linked to this user.'], 422);
        }

        $throttleKey = "inventory:fetched:{$user->steam_id}:{$appId}:{$contextId}";

        if ($request->boolean('fresh')) {
            Cache::forget($throttleKey);
        }

        // Serve from our own database if we refetched recently.
        if (Cache::has($throttleKey)) {
            return $this->inventoryResponse($appId, $contextId, $this->storedItems($user, $appId, $contextId));
        }

        $result = $this->fetchInventory($user->steam_id, $appId, $contextId);

        return match ($result['status']) {
            'ok' => tap(
                $this->inventoryResponse($appId, $contextId, $this->sync->sync($user, $appId, $contextId, $result['items'])),
                fn () => Cache::put($throttleKey, true, now()->addMinutes(self::REFETCH_THROTTLE_MINUTES)),
            ),
            'private' => response()->json([
                'message' => 'This Steam inventory is private. Set it to public to load your items.',
                'code' => 'inventory_private',
            ], 409),
            // On a transient upstream failure, fall back to the last known inventory.
            default => $this->fallbackOrError($user, $appId, $contextId),
        };
    }

    /**
     * Load the user's persisted items for the given app/context.
     *
     * @return Collection<int, InventoryItem>
     */
    private function storedItems(User $user, int $appId, int $contextId): Collection
    {
        return InventoryItem::with('itemDescription')
            ->where('user_id', $user->id)
            ->where('app_id', $appId)
            ->where('context_id', $contextId)
            ->orderByDesc('tradable')
            ->orderBy('id')
            ->get();
    }

    /**
     * Return stored items when Steam is unreachable, or a 502 if we have none.
     */
    private function fallbackOrError(User $user, int $appId, int $contextId): JsonResponse
    {
        $stored = $this->storedItems($user, $appId, $contextId);

        if ($stored->isNotEmpty()) {
            return $this->inventoryResponse($appId, $contextId, $stored, stale: true);
        }

        return response()->json(['message' => 'Unable to load inventory from Steam.'], 502);
    }

    /**
     * Build the success JSON envelope for a set of items.
     *
     * @param  Collection<int, InventoryItem>  $items
     */
    private function inventoryResponse(int $appId, int $contextId, Collection $items, bool $stale = false): JsonResponse
    {
        return response()->json([
            'app_id' => $appId,
            'context_id' => $contextId,
            'count' => $items->count(),
            'stale' => $stale,
            'items' => InventoryItemResource::collection($items),
        ]);
    }

    /**
     * Fetch the raw inventory from SteamAPIs and merge assets with descriptions.
     *
     * @return array{status: 'ok', items: array<int, array<string, mixed>>}|array{status: 'private'|'error'}
     */
    private function fetchInventory(string $steamId, int $appId, int $contextId): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.steamapis.key'),
            ])->acceptJson()->timeout(15)->get(
                config('services.steamapis.base_url')."/steam/users/{$steamId}/inventory/{$appId}/{$contextId}"
            );
        } catch (ConnectionException) {
            return ['status' => 'error'];
        }

        if ($this->indicatesPrivateInventory($response)) {
            return ['status' => 'private'];
        }

        if ($response->failed()) {
            return ['status' => 'error'];
        }

        $descriptions = collect($response->json('descriptions', []))
            ->keyBy(fn (array $description): string => $description['classid'].'_'.($description['instanceid'] ?? '0'));

        $items = collect($response->json('assets', []))
            ->map(function (array $asset) use ($descriptions): ?array {
                $description = $descriptions->get($asset['classid'].'_'.($asset['instanceid'] ?? '0'));

                if ($description === null) {
                    return null;
                }

                $tradable = (bool) ($description['tradable'] ?? false);

                return [
                    'asset_id' => $asset['assetid'] ?? null,
                    'class_id' => $asset['classid'] ?? null,
                    'instance_id' => $asset['instanceid'] ?? null,
                    'amount' => (int) ($asset['amount'] ?? 1),
                    'name' => $description['name'] ?? null,
                    'market_name' => $description['market_name'] ?? null,
                    'market_hash_name' => $description['market_hash_name'] ?? null,
                    'type' => $description['type'] ?? null,
                    'tradable' => $tradable,
                    'tradable_after' => $tradable ? null : $this->resolveTradableAfter($description),
                    'trade_hold_days' => isset($description['market_tradable_restriction'])
                        ? (int) $description['market_tradable_restriction']
                        : null,
                    'marketable' => (bool) ($description['marketable'] ?? false),
                    'icon_url' => $description['icon_url'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return ['status' => 'ok', 'items' => $items];
    }

    /**
     * Determine whether the upstream response represents a private inventory.
     *
     * Steam returns HTTP 403 for private profiles; aggregators sometimes proxy
     * that as a body-level error instead, so we check both.
     */
    private function indicatesPrivateInventory(Response $response): bool
    {
        if ($response->status() === 403) {
            return true;
        }

        $error = strtolower((string) ($response->json('Error') ?? $response->json('error') ?? ''));

        return str_contains($error, 'private');
    }

    /**
     * Resolve the exact trade-lock expiry from a description's owner_descriptions.
     *
     * Steam annotates locked items with a line such as
     * "Tradable After 14 Jul 2026 (07:00:00) GMT". This is only reliably present
     * when the inventory is fetched in the owner's context; returns null otherwise.
     *
     * @param  array<string, mixed>  $description
     */
    private function resolveTradableAfter(array $description): ?CarbonImmutable
    {
        foreach ($description['owner_descriptions'] ?? [] as $line) {
            $value = is_array($line) ? ($line['value'] ?? '') : '';

            if (! preg_match('/After\s+(\d{1,2}\s+[A-Za-z]+\s+\d{4})(?:\s*\(([\d:]+)\))?/i', $value, $matches)) {
                continue;
            }

            $datetime = trim($matches[1].' '.($matches[2] ?? '00:00:00'));

            try {
                return CarbonImmutable::createFromFormat('j M Y H:i:s', $datetime, 'GMT')->utc();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
