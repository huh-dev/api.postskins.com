<?php

namespace App\Models;

use App\Enums\OfferItemSide;
use App\Enums\TradeOfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'trade_post_id', 'offerer_id', 'cash_amount', 'cash_payer', 'currency',
    'message', 'status', 'trade_id',
])]
class TradeOffer extends Model
{
    use HasFactory;

    /** The offerer pays the cash. */
    public const PAYER_OFFERER = 'offerer';

    /** The post owner pays the cash. */
    public const PAYER_POSTER = 'poster';

    /**
     * @return BelongsTo<TradePost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(TradePost::class, 'trade_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function offerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offerer_id');
    }

    /**
     * @return HasMany<TradeOfferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TradeOfferItem::class);
    }

    /**
     * The assets the offerer hands over.
     *
     * @return HasMany<TradeOfferItem, $this>
     */
    public function offererItems(): HasMany
    {
        return $this->items()->where('side', OfferItemSide::FromOfferer);
    }

    /**
     * The post owner's assets the offerer wants in return.
     *
     * @return HasMany<TradeOfferItem, $this>
     */
    public function posterItems(): HasMany
    {
        return $this->items()->where('side', OfferItemSide::FromPoster);
    }

    /**
     * @return BelongsTo<Trade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TradeOfferStatus::class,
            'cash_amount' => 'integer',
        ];
    }
}
