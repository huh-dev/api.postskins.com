<?php

namespace App\Models;

use App\Enums\PostItemSide;
use App\Enums\TradePostStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'owner_id', 'app_id', 'context_id', 'offer_cash', 'want_cash', 'currency',
    'wants_anything', 'note', 'status', 'accepted_offer_id', 'expires_at',
])]
class TradePost extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<TradePostItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TradePostItem::class);
    }

    /**
     * The concrete assets the owner is putting up.
     *
     * @return HasMany<TradePostItem, $this>
     */
    public function offeringItems(): HasMany
    {
        return $this->items()->where('side', PostItemSide::Offering);
    }

    /**
     * The item descriptions the owner wants back (wishes, not assets).
     *
     * @return HasMany<TradePostItem, $this>
     */
    public function wantingItems(): HasMany
    {
        return $this->items()->where('side', PostItemSide::Wanting);
    }

    /**
     * @return HasMany<TradeOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(TradeOffer::class);
    }

    /**
     * @return BelongsTo<TradeOffer, $this>
     */
    public function acceptedOffer(): BelongsTo
    {
        return $this->belongsTo(TradeOffer::class, 'accepted_offer_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TradePostStatus::class,
            'offer_cash' => 'integer',
            'want_cash' => 'integer',
            'wants_anything' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}
