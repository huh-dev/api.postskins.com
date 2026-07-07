<?php

namespace App\Http\Resources;

use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryItem
 */
class InventoryItemResource extends JsonResource
{
    /**
     * Base URL for Steam economy item images (icon_url is a relative path).
     */
    private const STEAM_IMAGE_BASE = 'https://community.cloudflare.steamstatic.com/economy/image/';

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $description = $this->itemDescription;

        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'class_id' => $description->classid,
            'instance_id' => $description->instanceid,
            'amount' => $this->amount,
            'name' => $description->name,
            'market_name' => $description->market_name,
            'market_hash_name' => $description->market_hash_name,
            'type' => $description->type,
            'tradable' => $this->tradable,
            'tradable_after' => $this->tradable_after?->toIso8601String(),
            'trade_hold_days' => $description->trade_hold_days,
            'marketable' => $description->marketable,
            'icon_url' => $this->resolveIconUrl($description->icon_url),
        ];
    }

    /**
     * Resolve a relative Steam icon_url to an absolute CDN URL.
     */
    private function resolveIconUrl(?string $iconUrl): ?string
    {
        if (! $iconUrl) {
            return null;
        }

        if (str_starts_with($iconUrl, 'http')) {
            return $iconUrl;
        }

        return self::STEAM_IMAGE_BASE.$iconUrl;
    }
}
