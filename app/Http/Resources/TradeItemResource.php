<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesSteamIcon;
use App\Models\TradeItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TradeItem
 */
class TradeItemResource extends JsonResource
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
            'giver_id' => $this->giver_id,
            'receiver_id' => $this->receiver_id,
            'market_hash_name' => $this->market_hash_name,
            'asset_id_sent' => $this->asset_id_sent,
            'asset_id_received' => $this->asset_id_received,
            'received_at' => $this->received_seen_at?->toIso8601String(),
            'item' => [
                'name' => $description?->name,
                'market_hash_name' => $description?->market_hash_name,
                'type' => $description?->type,
                'icon_url' => $this->resolveIconUrl($description?->icon_url),
            ],
        ];
    }
}
