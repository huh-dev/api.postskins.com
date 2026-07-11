<?php

namespace App\Jobs;

use App\Enums\TradeItemSide;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Services\Trading\GcClient;
use App\Services\Trading\TradeVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one atomic Steam offer carrying both parties' items, from the initiator
 * (the post owner) to the counterparty's trade URL. The initiator confirms the
 * outgoing offer on their mobile authenticator; the counterparty accepts it,
 * moving every leg at once. The poller takes over from there.
 *
 * A 4xx from the GC service means the request itself is bad — typically an item
 * that moved or became untradable since the offer was accepted. That is terminal:
 * we refund any cash and cancel, rather than retrying a send that can never work.
 */
class SendTradeOffer implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $tradeId) {}

    public function handle(GcClient $gc, TradeVerifier $verifier): void
    {
        $trade = Trade::with(['initiator', 'counterparty', 'items'])->find($this->tradeId);

        if ($trade === null) {
            Log::warning('SendTradeOffer: trade not found', ['trade_id' => $this->tradeId]);

            return;
        }

        if ($trade->steam_tradeoffer_id !== null) {
            Log::info('SendTradeOffer: offer already sent, skipping', ['trade_id' => $trade->id, 'offer_id' => $trade->steam_tradeoffer_id]);

            return;
        }

        $initiator = $trade->initiator;
        $counterparty = $trade->counterparty;

        if (! $initiator->isSellingConnected()) {
            Log::warning('SendTradeOffer: initiator not connected — no offer sent', ['trade_id' => $trade->id, 'initiator_id' => $initiator->id]);
            $trade->recordEvent('offer_send_failed', ['reason' => 'initiator_not_connected']);
            $verifier->cancelDelivery($trade, 'initiator_not_connected');

            return;
        }

        if (! $counterparty->trade_url) {
            Log::warning('SendTradeOffer: counterparty has no trade URL — no offer sent', ['trade_id' => $trade->id, 'counterparty_id' => $counterparty->id]);
            $trade->recordEvent('offer_send_failed', ['reason' => 'counterparty_no_trade_url']);
            $verifier->cancelDelivery($trade, 'counterparty_no_trade_url');

            return;
        }

        $myItems = $this->assetPayload($trade, TradeItemSide::FromInitiator);
        $theirItems = $this->assetPayload($trade, TradeItemSide::FromCounterparty);

        Log::info('SendTradeOffer: starting', [
            'trade_id' => $trade->id,
            'my_items' => count($myItems),
            'their_items' => count($theirItems),
        ]);

        try {
            $result = $gc->sendOffer(
                $initiator->steam_refresh_token,
                $counterparty->trade_url,
                $myItems,
                $theirItems,
                "Postskins trade #{$trade->id}",
            );
        } catch (RequestException $e) {
            $reason = $e->response->json('error') ?? $e->getMessage();

            // A bad request (invalid/moved items) can never succeed on retry.
            if ($e->response->clientError()) {
                Log::error('SendTradeOffer: GC rejected the offer, cancelling', ['trade_id' => $trade->id, 'status' => $e->response->status(), 'reason' => $reason]);
                $trade->recordEvent('offer_send_failed', ['reason' => $reason, 'terminal' => true]);
                $verifier->cancelDelivery($trade, 'offer_rejected');

                return;
            }

            Log::error('SendTradeOffer: GC send failed', ['trade_id' => $trade->id, 'status' => $e->response->status(), 'reason' => $reason]);
            $trade->recordEvent('offer_send_failed', ['reason' => $reason]);

            throw $e; // upstream/server error — allow retry/backoff
        } catch (ConnectionException $e) {
            Log::error('SendTradeOffer: GC unreachable', ['trade_id' => $trade->id, 'gc_url' => config('services.gc.url'), 'message' => $e->getMessage()]);
            $trade->recordEvent('offer_send_failed', ['reason' => 'gc_unreachable']);

            throw $e;
        }

        $trade->forceFill(['steam_tradeoffer_id' => $result['tradeOfferId'] ?? null])->save();
        $trade->recordEvent('offer_sent', [
            'steam_tradeoffer_id' => $result['tradeOfferId'] ?? null,
            'state' => $result['state'] ?? null,
        ]);

        Log::info('SendTradeOffer: offer sent', [
            'trade_id' => $trade->id,
            'steam_tradeoffer_id' => $result['tradeOfferId'] ?? null,
            'state' => $result['state'] ?? null,
        ]);
    }

    /**
     * The Steam asset payload for one side of the trade.
     *
     * @return list<array{appid: int, contextid: string, assetid: string}>
     */
    private function assetPayload(Trade $trade, TradeItemSide $side): array
    {
        return $trade->items
            ->where('side', $side)
            ->map(fn (TradeItem $leg): array => [
                'appid' => (int) $leg->app_id,
                'contextid' => (string) $leg->context_id,
                'assetid' => $leg->asset_id_sent,
            ])
            ->values()
            ->all();
    }

    /**
     * Back off when the GC service is flaky rather than burning retries.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new ThrottlesExceptions(3, 2 * 60))->by("send-offer:{$this->tradeId}")];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendTradeOffer: permanently failed', ['trade_id' => $this->tradeId, 'message' => $exception->getMessage()]);

        $trade = Trade::find($this->tradeId);

        if ($trade === null) {
            return;
        }

        $trade->recordEvent('offer_send_failed', ['reason' => $exception->getMessage(), 'permanent' => true]);

        // Never leave the payer's cash held on a send that can no longer complete.
        app(TradeVerifier::class)->cancelDelivery($trade, 'offer_send_failed');
    }
}
