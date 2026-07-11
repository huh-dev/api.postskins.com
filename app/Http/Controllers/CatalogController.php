<?php

namespace App\Http\Controllers;

use App\Http\Resources\Concerns\ResolvesSteamIcon;
use App\Models\ItemDescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Search the item catalog when a user picks the items they WANT on a post.
 *
 * The catalog is `item_descriptions`, which only holds items someone on the
 * platform has synced. Items nobody here owns yet are not findable — the
 * "open to offers" flag on a post covers that gap for now.
 */
class CatalogController extends Controller
{
    use ResolvesSteamIcon;

    public function items(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'app_id' => ['nullable', 'integer'],
        ]);

        $query = ItemDescription::query()
            ->when(! empty($validated['app_id']), fn ($q) => $q->where('app_id', $validated['app_id']))
            ->when(
                ! empty($validated['search']),
                fn ($q) => $q->where('market_hash_name', 'like', '%'.$validated['search'].'%'),
            )
            ->orderBy('market_hash_name')
            ->limit(30);

        $items = $query->get()->map(fn (ItemDescription $d): array => [
            'item_description_id' => $d->id,
            'name' => $d->name,
            'market_hash_name' => $d->market_hash_name,
            'type' => $d->type,
            'icon_url' => $this->resolveIconUrl($d->icon_url),
        ]);

        return response()->json(['items' => $items]);
    }
}
