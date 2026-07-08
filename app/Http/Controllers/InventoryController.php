<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\Steam\InventoryProvider;
use App\Services\Steam\InventoryResult;
use App\Services\Steam\OfficialSteamInventoryProvider;
use App\Services\SteamInventorySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InventoryController extends Controller
{
    /**
     * How long to wait before re-fetching the same inventory from Steam.
     */
    private const REFETCH_THROTTLE_MINUTES = 5;

    public function __construct(
        private readonly SteamInventorySync $sync,
        private readonly InventoryProvider $provider,
    ) {}

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

        return match ($result->status) {
            'ok' => tap(
                $this->inventoryResponse($appId, $contextId, $this->sync->sync($user, $appId, $contextId, $result->items)),
                fn () => Cache::put($throttleKey, true, now()->addMinutes(self::REFETCH_THROTTLE_MINUTES)),
            ),
            'private' => response()->json([
                'message' => 'This Steam inventory is private. Set it to public to load your items.',
                'code' => 'inventory_private',
            ], 409),
            // On a transient upstream failure, fall back to the last known inventory.
            default => $this->fallbackOrError($user, $appId, $contextId, $result->message),
        };
    }

    /**
     * Fetch from the configured provider, falling back to Steam's public
     * endpoint when SteamAPIs errors (e.g. quota exhausted). If both sources
     * fail, the returned result carries each provider's error message.
     */
    private function fetchInventory(string $steamId, int $appId, int $contextId): InventoryResult
    {
        $result = $this->provider->fetch($steamId, $appId, $contextId);

        // Only the SteamAPIs driver has a secondary source to fall back on.
        if ($result->status !== 'error' || config('services.steam_inventory.driver') !== 'steamapis') {
            return $result;
        }

        $fallback = app(OfficialSteamInventoryProvider::class)->fetch($steamId, $appId, $contextId);

        if ($fallback->status !== 'error') {
            return $fallback;
        }

        // Both sources failed — log and surface each provider's error so the
        // underlying cause (quota, rate limit, outage…) is visible.
        Log::warning('Both inventory providers failed.', [
            'steam_id' => $steamId,
            'app_id' => $appId,
            'context_id' => $contextId,
            'steamapis_error' => $result->message,
            'official_error' => $fallback->message,
        ]);

        return InventoryResult::error(sprintf(
            'SteamAPIs: %s — Steam: %s',
            $result->message ?? 'unknown error',
            $fallback->message ?? 'unknown error',
        ));
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
    private function fallbackOrError(User $user, int $appId, int $contextId, ?string $upstreamMessage = null): JsonResponse
    {
        $stored = $this->storedItems($user, $appId, $contextId);

        if ($stored->isNotEmpty()) {
            return $this->inventoryResponse($appId, $contextId, $stored, stale: true, error: $upstreamMessage);
        }

        return response()->json([
            'message' => $upstreamMessage ?? 'Unable to load inventory from Steam.',
            'code' => 'inventory_unavailable',
        ], 502);
    }

    /**
     * Build the success JSON envelope for a set of items.
     *
     * @param  Collection<int, InventoryItem>  $items
     */
    private function inventoryResponse(int $appId, int $contextId, Collection $items, bool $stale = false, ?string $error = null): JsonResponse
    {
        return response()->json([
            'app_id' => $appId,
            'context_id' => $contextId,
            'count' => $items->count(),
            'stale' => $stale,
            'error' => $error,
            'items' => InventoryItemResource::collection($items),
        ]);
    }
}
