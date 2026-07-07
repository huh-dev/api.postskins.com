<?php

namespace App\Http\Resources;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 */
class ListingResource extends JsonResource
{
    private const STEAM_IMAGE_BASE = 'https://community.cloudflare.steamstatic.com/economy/image/';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $description = $this->itemDescription;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'price' => $this->price,
            'currency' => $this->currency,
            'market_hash_name' => $this->market_hash_name,
            'item' => [
                'name' => $description?->name,
                'market_hash_name' => $description?->market_hash_name,
                'icon_url' => $this->resolveIconUrl($description?->icon_url),
            ],
            'seller' => $this->whenLoaded('seller', fn (): array => [
                'id' => $this->seller->id,
                'name' => $this->seller->name,
                'avatar' => $this->seller->avatar,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function resolveIconUrl(?string $iconUrl): ?string
    {
        if (! $iconUrl) {
            return null;
        }

        return str_starts_with($iconUrl, 'http') ? $iconUrl : self::STEAM_IMAGE_BASE.$iconUrl;
    }
}
