<?php

namespace App\Services\Trading;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the Node "GC" service, which turns a seller's refresh token
 * into a web session and sends P2P trade offers on their behalf. The service is
 * internal; requests are authenticated with a shared secret header.
 */
class GcClient
{
    /**
     * Begin a seller authorization (QR). Returns ['id' => ..., 'qrUrl' => ...].
     *
     * @return array<string, mixed>
     */
    public function startSellerAuth(): array
    {
        return $this->request()->post($this->url('/auth/qr/start'))->throw()->json();
    }

    /**
     * Poll a seller authorization; on success includes refreshToken + steamId.
     *
     * @return array<string, mixed>
     */
    public function pollSellerAuth(string $id): array
    {
        return $this->request()->get($this->url("/auth/qr/{$id}"))->throw()->json();
    }

    /**
     * Send a trade offer from the seller (via their refresh token) to a buyer.
     *
     * @param  array{appid: int, contextid: string, assetid: string}  $item
     * @return array<string, mixed>
     */
    public function sendOffer(string $refreshToken, string $tradeUrl, array $item, ?string $message = null): array
    {
        return $this->request()
            ->post($this->url('/trade/send-offer'), [
                'refresh_token' => $refreshToken,
                'trade_url' => $tradeUrl,
                'item' => $item,
                'message' => $message,
            ])
            ->throw()
            ->json();
    }

    /**
     * Look up the live state of a previously sent offer (via the seller session).
     *
     * @return array<string, mixed>
     */
    public function offerState(string $refreshToken, string $offerId): array
    {
        return $this->request()
            ->post($this->url('/trade/offer-state'), [
                'refresh_token' => $refreshToken,
                'offer_id' => $offerId,
            ])
            ->throw()
            ->json();
    }

    /**
     * Authoritatively check whether a completed trade has been rolled back.
     *
     * @return array<string, mixed>
     */
    public function exchangeStatus(string $refreshToken, string $offerId): array
    {
        return $this->request()
            ->post($this->url('/trade/exchange-status'), [
                'refresh_token' => $refreshToken,
                'offer_id' => $offerId,
            ])
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['x-gc-secret' => (string) config('services.gc.secret')])
            ->acceptJson()
            ->timeout(30);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.gc.url'), '/').$path;
    }
}
