<?php

namespace App\Http\Resources;

use App\Enums\PostItemSide;
use App\Models\TradePost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TradePost
 */
class TradePostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'app_id' => $this->app_id,
            'context_id' => $this->context_id,
            'offer_cash' => $this->offer_cash,
            'want_cash' => $this->want_cash,
            'currency' => $this->currency,
            'wants_anything' => $this->wants_anything,
            'note' => $this->note,
            'owner' => new UserSummaryResource($this->whenLoaded('owner')),
            'offering' => TradePostItemResource::collection(
                $this->whenLoaded('items', fn () => $items->where('side', PostItemSide::Offering)->values()),
            ),
            'wanting' => TradePostItemResource::collection(
                $this->whenLoaded('items', fn () => $items->where('side', PostItemSide::Wanting)->values()),
            ),
            'offers_count' => $this->whenCounted('offers'),
            // Only present when the owner is viewing their own post's inbox.
            'offers' => TradeOfferResource::collection($this->whenLoaded('offers')),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
