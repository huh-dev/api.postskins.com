<?php

namespace App\Models;

use App\Enums\TradeItemSide;
use App\Enums\TradeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'trade_post_id', 'trade_offer_id', 'initiator_id', 'counterparty_id',
    'app_id', 'context_id', 'cash_amount', 'cash_payer_id', 'cash_payee_id', 'currency',
    'steam_tradeoffer_id', 'status', 'escrow', 'needs_review', 'protection_expires_at',
    'accepted_at', 'completed_at', 'reversed_at', 'last_polled_at', 'next_poll_at',
])]
class Trade extends Model
{
    use HasFactory;

    /**
     * The party that sends the Steam offer (the post owner).
     *
     * @return BelongsTo<User, $this>
     */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    /**
     * The party that accepts the Steam offer (the offerer).
     *
     * @return BelongsTo<User, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashPayer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_payer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashPayee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_payee_id');
    }

    /**
     * @return BelongsTo<TradePost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(TradePost::class, 'trade_post_id');
    }

    /**
     * @return BelongsTo<TradeOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(TradeOffer::class, 'trade_offer_id');
    }

    /**
     * Every asset moving in either direction.
     *
     * @return HasMany<TradeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TradeItem::class);
    }

    /**
     * @return HasMany<TradeEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TradeEvent::class);
    }

    /**
     * The legs leaving the initiator (attached to the Steam offer as "my items").
     *
     * @return Collection<int, TradeItem>
     */
    public function initiatorItems(): Collection
    {
        return $this->items->where('side', TradeItemSide::FromInitiator)->values();
    }

    /**
     * The legs leaving the counterparty (attached as "their items").
     *
     * @return Collection<int, TradeItem>
     */
    public function counterpartyItems(): Collection
    {
        return $this->items->where('side', TradeItemSide::FromCounterparty)->values();
    }

    /**
     * A pure cash purchase: the counterparty pays money and hands over nothing.
     * Only these can be blamed on the initiator when reversed.
     */
    public function isCashPurchase(): bool
    {
        return $this->counterpartyItems()->isEmpty();
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
            'cash_amount' => 'integer',
            'escrow' => 'boolean',
            'needs_review' => 'boolean',
            'protection_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'reversed_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'next_poll_at' => 'datetime',
        ];
    }
}
