<?php

namespace Database\Factories;

use App\Enums\TradeItemSide;
use App\Enums\TradeStatus;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'initiator_id' => User::factory(),
            'counterparty_id' => User::factory(),
            'app_id' => 730,
            'context_id' => 2,
            'cash_amount' => 0,
            'cash_payer_id' => null,
            'cash_payee_id' => null,
            'currency' => config('trades.currency'),
            'status' => TradeStatus::PendingDelivery,
            'escrow' => false,
            'needs_review' => false,
            'next_poll_at' => now(),
        ];
    }

    /**
     * The two parties: the initiator sends the Steam offer, the counterparty accepts it.
     */
    public function between(User $initiator, User $counterparty): static
    {
        return $this->state(fn (): array => [
            'initiator_id' => $initiator->id,
            'counterparty_id' => $counterparty->id,
        ]);
    }

    /**
     * The counterparty pays the initiator to balance the trade.
     */
    public function counterpartyPays(int $amount): static
    {
        return $this->cash($amount, payerIsCounterparty: true);
    }

    /**
     * The initiator pays the counterparty to balance the trade.
     */
    public function initiatorPays(int $amount): static
    {
        return $this->cash($amount, payerIsCounterparty: false);
    }

    /**
     * Attach a leg leaving the initiator, from one of their real assets.
     */
    public function fromInitiator(InventoryItem $item): static
    {
        return $this->withLeg(TradeItemSide::FromInitiator, $item);
    }

    /**
     * Attach a leg leaving the counterparty, from one of their real assets.
     */
    public function fromCounterparty(InventoryItem $item): static
    {
        return $this->withLeg(TradeItemSide::FromCounterparty, $item);
    }

    /**
     * Resolved after creation, since the party ids may still be factories at state time.
     */
    private function cash(int $amount, bool $payerIsCounterparty): static
    {
        return $this->afterCreating(function (Trade $trade) use ($amount, $payerIsCounterparty): void {
            $trade->forceFill([
                'cash_amount' => $amount,
                'cash_payer_id' => $payerIsCounterparty ? $trade->counterparty_id : $trade->initiator_id,
                'cash_payee_id' => $payerIsCounterparty ? $trade->initiator_id : $trade->counterparty_id,
            ])->save();
        });
    }

    private function withLeg(TradeItemSide $side, InventoryItem $item): static
    {
        return $this->afterCreating(function (Trade $trade) use ($side, $item): void {
            $fromInitiator = $side === TradeItemSide::FromInitiator;

            TradeItem::create([
                'trade_id' => $trade->id,
                'side' => $side,
                'giver_id' => $fromInitiator ? $trade->initiator_id : $trade->counterparty_id,
                'receiver_id' => $fromInitiator ? $trade->counterparty_id : $trade->initiator_id,
                'item_description_id' => $item->item_description_id,
                'app_id' => $item->app_id,
                'context_id' => $item->context_id,
                'market_hash_name' => $item->itemDescription->market_hash_name,
                'asset_id_sent' => $item->asset_id,
            ]);
        });
    }
}
