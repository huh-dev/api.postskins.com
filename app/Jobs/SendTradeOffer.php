<?php

namespace App\Jobs;

use App\Models\Trade;
use App\Services\Trading\GcClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the seller's item to the buyer automatically via the GC service. The
 * seller then only has to confirm the outgoing offer on their mobile
 * authenticator; the poller detects the item's arrival from there.
 */
class SendTradeOffer implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $tradeId) {}

    public function handle(GcClient $gc): void
    {
        $trade = Trade::with(['seller', 'buyer'])->find($this->tradeId);

        if ($trade === null) {
            Log::warning('SendTradeOffer: trade not found', ['trade_id' => $this->tradeId]);

            return;
        }

        if ($trade->steam_tradeoffer_id !== null) {
            Log::info('SendTradeOffer: offer already sent, skipping', ['trade_id' => $trade->id, 'offer_id' => $trade->steam_tradeoffer_id]);

            return;
        }

        $seller = $trade->seller;
        $buyer = $trade->buyer;

        Log::info('SendTradeOffer: starting', [
            'trade_id' => $trade->id,
            'seller_connected' => $seller->isSellingConnected(),
            'buyer_has_trade_url' => (bool) $buyer->trade_url,
            'asset_id' => $trade->asset_id_listed,
        ]);

        if (! $seller->isSellingConnected()) {
            Log::warning('SendTradeOffer: seller not connected for selling — no offer sent', ['trade_id' => $trade->id, 'seller_id' => $seller->id]);
            $trade->recordEvent('offer_send_failed', ['reason' => 'seller_not_connected']);

            return;
        }

        if (! $buyer->trade_url) {
            Log::warning('SendTradeOffer: buyer has no trade URL — no offer sent', ['trade_id' => $trade->id, 'buyer_id' => $buyer->id]);
            $trade->recordEvent('offer_send_failed', ['reason' => 'buyer_no_trade_url']);

            return;
        }

        try {
            $result = $gc->sendOffer(
                $seller->steam_refresh_token,
                $buyer->trade_url,
                ['appid' => $trade->app_id, 'contextid' => (string) $trade->context_id, 'assetid' => $trade->asset_id_listed],
                "Postskins order #{$trade->id}",
            );
        } catch (RequestException $e) {
            // The GC service reached Steam but the send failed — capture why.
            $reason = $e->response->json('error') ?? $e->getMessage();
            Log::error('SendTradeOffer: GC send failed', ['trade_id' => $trade->id, 'status' => $e->response->status(), 'reason' => $reason]);
            $trade->recordEvent('offer_send_failed', ['reason' => $reason]);

            throw $e; // allow retry/backoff
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
        Trade::find($this->tradeId)?->recordEvent('offer_send_failed', ['reason' => $exception->getMessage()]);
    }
}
