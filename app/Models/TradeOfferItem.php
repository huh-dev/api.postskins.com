<?php

namespace App\Models;

use App\Enums\OfferItemSide;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trade_offer_id', 'side', 'item_description_id', 'inventory_item_id',
    'app_id', 'context_id', 'market_hash_name', 'asset_id',
])]
class TradeOfferItem extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<TradeOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(TradeOffer::class, 'trade_offer_id');
    }

    /**
     * @return BelongsTo<ItemDescription, $this>
     */
    public function itemDescription(): BelongsTo
    {
        return $this->belongsTo(ItemDescription::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => OfferItemSide::class,
        ];
    }
}
