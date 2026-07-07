<?php

namespace App\Http\Resources;

use App\Models\Trade;
use App\Support\SteamId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Trade
 */
class TradeResource extends JsonResource
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
            'app_id' => $this->app_id,
            'context_id' => $this->context_id,
            'market_hash_name' => $this->market_hash_name,
            'item' => [
                'name' => $description?->name,
                'market_hash_name' => $description?->market_hash_name,
                'icon_url' => $this->resolveIconUrl($description?->icon_url),
            ],
            'seller' => $this->userSummary($this->whenLoaded('seller')),
            'buyer' => $this->userSummary($this->whenLoaded('buyer')),
            // The link the seller opens in Steam to deliver the item to the buyer.
            'steam_trade_link' => $this->when(
                $this->relationLoaded('buyer') && $this->buyer !== null,
                fn (): ?string => $this->buyer->steam_id
                    ? SteamId::tradeOfferLink($this->buyer->steam_id, $this->buyer->trade_url)
                    : null,
            ),
            'escrow' => $this->escrow,
            'protection_expires_at' => $this->protection_expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'events' => TradeEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userSummary(mixed $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'suspended' => $user->isSuspended(),
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
