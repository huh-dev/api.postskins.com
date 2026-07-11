<?php

namespace App\Models;

use App\Enums\PostItemSide;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trade_post_id', 'side', 'item_description_id', 'inventory_item_id',
    'app_id', 'context_id', 'market_hash_name', 'asset_id', 'quantity',
])]
class TradePostItem extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<TradePost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(TradePost::class, 'trade_post_id');
    }

    /**
     * @return BelongsTo<ItemDescription, $this>
     */
    public function itemDescription(): BelongsTo
    {
        return $this->belongsTo(ItemDescription::class);
    }

    /**
     * Null for `wanting` rows, which name a description but no concrete asset.
     *
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
            'side' => PostItemSide::class,
            'quantity' => 'integer',
        ];
    }
}
