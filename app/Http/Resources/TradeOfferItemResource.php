<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesSteamIcon;
use App\Models\TradeOfferItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TradeOfferItem
 */
class TradeOfferItemResource extends JsonResource
{
    use ResolvesSteamIcon;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $description = $this->itemDescription;

        return [
            'id' => $this->id,
            'side' => $this->side->value,
            'market_hash_name' => $this->market_hash_name,
            'asset_id' => $this->asset_id,
            'item' => [
                'name' => $description?->name,
                'market_hash_name' => $description?->market_hash_name,
                'type' => $description?->type,
                'icon_url' => $this->resolveIconUrl($description?->icon_url),
            ],
        ];
    }
}
