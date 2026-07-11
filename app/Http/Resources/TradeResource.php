<?php

namespace App\Http\Resources;

use App\Models\Trade;
use App\Support\SteamId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Trade
 */
class TradeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'app_id' => $this->app_id,
            'context_id' => $this->context_id,
            'cash_amount' => $this->cash_amount,
            'cash_payer_id' => $this->cash_payer_id,
            'cash_payee_id' => $this->cash_payee_id,
            'currency' => $this->currency,
            'initiator' => new UserSummaryResource($this->whenLoaded('initiator')),
            'counterparty' => new UserSummaryResource($this->whenLoaded('counterparty')),
            'items' => TradeItemResource::collection($this->whenLoaded('items')),
            // The link the initiator opens in Steam to send the offer to the counterparty.
            'steam_trade_link' => $this->when(
                $this->relationLoaded('counterparty') && $this->counterparty !== null,
                fn (): ?string => $this->counterparty->steam_id
                    ? SteamId::tradeOfferLink($this->counterparty->steam_id, $this->counterparty->trade_url)
                    : null,
            ),
            'escrow' => $this->escrow,
            'needs_review' => $this->needs_review,
            'protection_expires_at' => $this->protection_expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'events' => TradeEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
