<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'steam_id', 'app_id', 'context_id', 'asset_id', 'item_description_id', 'amount', 'tradable', 'tradable_after', 'first_seen_at', 'last_seen_at'])]
class InventoryItem extends Model
{
    /**
     * The shared metadata for this asset.
     *
     * @return BelongsTo<ItemDescription, $this>
     */
    public function itemDescription(): BelongsTo
    {
        return $this->belongsTo(ItemDescription::class);
    }

    /**
     * The owner of this asset.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tradable' => 'boolean',
            'tradable_after' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
