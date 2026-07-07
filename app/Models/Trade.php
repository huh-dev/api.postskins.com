<?php

namespace App\Models;

use App\Enums\TradeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'seller_id', 'buyer_id', 'item_description_id', 'app_id', 'context_id',
    'market_hash_name', 'asset_id_listed', 'asset_id_received', 'price', 'currency',
    'steam_tradeoffer_id', 'status', 'escrow', 'protection_expires_at',
    'accepted_at', 'completed_at', 'reversed_at', 'last_polled_at', 'next_poll_at',
])]
class Trade extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * @return BelongsTo<ItemDescription, $this>
     */
    public function itemDescription(): BelongsTo
    {
        return $this->belongsTo(ItemDescription::class);
    }

    /**
     * @return HasMany<TradeEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TradeEvent::class);
    }

    /**
     * Record an audit/evidence entry for this trade.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(string $type, array $payload = []): TradeEvent
    {
        return $this->events()->create(['type' => $type, 'payload' => $payload]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TradeStatus::class,
            'price' => 'integer',
            'escrow' => 'boolean',
            'protection_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'reversed_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'next_poll_at' => 'datetime',
        ];
    }
}
