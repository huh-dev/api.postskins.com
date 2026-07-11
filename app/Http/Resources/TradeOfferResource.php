<?php

namespace App\Http\Resources;

use App\Enums\OfferItemSide;
use App\Models\TradeOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TradeOffer
 */
class TradeOfferResource extends JsonResource
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
            'cash_amount' => $this->cash_amount,
            'cash_payer' => $this->cash_payer,
            'currency' => $this->currency,
            'message' => $this->message,
            'offerer' => new UserSummaryResource($this->whenLoaded('offerer')),
            // The post this offer is against — present when listing a user's own
            // offers, so each row knows its post and counterparty (the owner).
            'post' => $this->whenLoaded('post', fn (): array => [
                'id' => $this->post->id,
                'status' => $this->post->status->value,
                'owner' => new UserSummaryResource($this->post->owner),
            ]),
            // Assets the offerer hands over.
            'giving' => TradeOfferItemResource::collection(
                $this->whenLoaded('items', fn () => $items->where('side', OfferItemSide::FromOfferer)->values()),
            ),
            // The post owner's assets the offerer wants in return.
            'wanting' => TradeOfferItemResource::collection(
                $this->whenLoaded('items', fn () => $items->where('side', OfferItemSide::FromPoster)->values()),
            ),
            'trade_id' => $this->trade_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
