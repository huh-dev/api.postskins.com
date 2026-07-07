<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\Steam\InventoryProvider;
use App\Services\SteamInventorySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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

        $result = $this->provider->fetch($user->steam_id, $appId, $contextId);

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
}
