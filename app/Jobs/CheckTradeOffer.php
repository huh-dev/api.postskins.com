<?php

namespace App\Jobs;

use App\Enums\TradeStatus;
use App\Models\Trade;
use App\Services\Trading\GcClient;
use App\Services\Trading\TradeVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;

/**
 * Confirms delivery from the authoritative Steam offer state (via the seller's
 * session), rather than waiting for the buyer's cached inventory to update.
 * Accepted -> mark delivered; declined/expired/canceled -> refund the buyer.
 */
class CheckTradeOffer implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** ETradeOfferState values that mean the item is now with the buyer. */
    private const DELIVERED = [3 /* Accepted */, 11 /* InEscrow */];

    /** ETradeOfferState values that mean the offer will never complete. */
    private const DEAD = [1 /* Invalid */, 4 /* Countered */, 5 /* Expired */, 6 /* Canceled */, 7 /* Declined */, 8 /* InvalidItems */, 10 /* CanceledBySecondFactor */];

    public function __construct(public int $tradeId) {}

    public function handle(GcClient $gc, TradeVerifier $verifier): void
    {
        $trade = Trade::with('initiator')->find($this->tradeId);

        if ($trade === null || $trade->status !== TradeStatus::PendingDelivery) {
            return;
        }

        if ($trade->steam_tradeoffer_id === null || ! $trade->initiator->isSellingConnected()) {
            return;
        }

        try {
            $state = $gc->offerState($trade->initiator->steam_refresh_token, $trade->steam_tradeoffer_id);
        } catch (RequestException|ConnectionException $e) {
            Log::warning('CheckTradeOffer: offer-state lookup failed', ['trade_id' => $trade->id, 'message' => $e->getMessage()]);

            throw $e; // retry with backoff
        }

        $stateValue = (int) ($state['state'] ?? 0);

        Log::info('CheckTradeOffer: offer state', [
            'trade_id' => $trade->id,
            'offer_id' => $trade->steam_tradeoffer_id,
            'state' => $stateValue,
            'state_name' => $state['stateName'] ?? null,
        ]);

        if (in_array($stateValue, self::DELIVERED, true)) {
            $verifier->markDelivered($trade, $state['tradeId'] ?? null, $stateValue === 11);

            return;
        }

        if (in_array($stateValue, self::DEAD, true)) {
            $verifier->cancelDelivery($trade, 'offer_'.strtolower((string) ($state['stateName'] ?? 'ended')));

            return;
        }

        // Active (2) or CreatedNeedsConfirmation (9): still waiting on the seller
        // to confirm / buyer to accept. Nudge the next poll.
        $trade->forceFill(['next_poll_at' => now()->addSeconds((int) config('trades.poll.min_seconds')), 'last_polled_at' => now()])->save();

        if ($stateValue === 9 && ! $trade->events()->where('type', 'awaiting_confirmation')->exists()) {
            $trade->recordEvent('awaiting_confirmation', ['note' => 'Waiting for the seller to confirm the offer on their mobile authenticator.']);
        }
    }

    /**
     * Stop hammering Steam login if checks start failing (rate limits, etc.).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new ThrottlesExceptions(3, 10 * 60))->by("offer-check:{$this->tradeId}")];
    }
}
