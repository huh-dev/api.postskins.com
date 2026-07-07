<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['app_id', 'classid', 'instanceid', 'name', 'market_name', 'market_hash_name', 'type', 'icon_url', 'marketable', 'trade_hold_days'])]
class ItemDescription extends Model
{
    use HasFactory;

    /**
     * The assets across all users that share this description.
     *
     * @return HasMany<InventoryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marketable' => 'boolean',
        ];
    }
}
