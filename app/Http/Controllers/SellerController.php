<?php

namespace App\Http\Controllers;

use App\Services\Trading\GcClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Lets a seller authorize the GC service to send trade offers on their behalf,
 * via a one-time Steam QR login. The resulting refresh token is stored encrypted
 * on the user and never returned to the client.
 */
class SellerController extends Controller
{
    public function __construct(private readonly GcClient $gc) {}

    /**
     * Whether the authenticated seller is connected for selling.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'connected' => $user->isSellingConnected(),
            'connected_at' => $user->steam_selling_connected_at?->toIso8601String(),
        ]);
    }

    /**
     * Forget the seller's stored token so they can re-authorize (e.g. after the
     * token expires or is invalidated by Steam).
     */
    public function disconnect(Request $request): JsonResponse
    {
        $request->user()->forceFill([
            'steam_refresh_token' => null,
            'steam_selling_connected_at' => null,
        ])->save();

        return response()->json(['connected' => false]);
    }

    /**
     * Begin a QR authorization; returns a challenge URL for the client to render.
     */
    public function startConnect(Request $request): JsonResponse
    {
        try {
            $result = $this->gc->startSellerAuth();
        } catch (RequestException|ConnectionException $e) {
            return $this->gcError('startSellerAuth', $e);
        }

        return response()->json([
            'id' => $result['id'],
            'qr_url' => $result['qrUrl'] ?? null,
        ]);
    }

    /**
     * Poll a QR authorization. On success, stores the seller's refresh token —
     * but only if it authorizes *their own* linked Steam account.
     */
    public function connectStatus(Request $request, string $id): JsonResponse
    {
        try {
            $result = $this->gc->pollSellerAuth($id);
        } catch (RequestException|ConnectionException $e) {
            return $this->gcError('pollSellerAuth', $e);
        }

        if (($result['status'] ?? null) !== 'authenticated') {
            return response()->json(['status' => $result['status'] ?? 'unknown']);
        }

        $user = $request->user();

        if (($result['steamId'] ?? null) !== $user->steam_id) {
            return response()->json([
                'status' => 'wrong_account',
                'message' => 'That Steam account does not match your signed-in account.',
            ], 422);
        }

        $user->forceFill([
            'steam_refresh_token' => $result['refreshToken'],
            'steam_selling_connected_at' => now(),
        ])->save();

        Log::info('Seller connected for selling', ['user_id' => $user->id, 'steam_id' => $user->steam_id]);

        return response()->json(['status' => 'connected']);
    }

    /**
     * Log the real reason the GC call failed and surface it in local dev.
     */
    private function gcError(string $action, RequestException|ConnectionException $e): JsonResponse
    {
        $status = $e instanceof RequestException ? $e->response->status() : null;
        $body = $e instanceof RequestException ? $e->response->body() : null;

        Log::warning("GC {$action} failed", [
            'gc_url' => config('services.gc.url'),
            'status' => $status,
            'body' => $body,
            'message' => $e->getMessage(),
        ]);

        $hint = match ($status) {
            401 => 'GC rejected the request (401) — GC_SHARED_SECRET does not match between the API and the GC service.',
            null => 'Could not reach the GC service — is it running on '.config('services.gc.url').'?',
            default => "The trade service returned an error ({$status}).",
        };

        return response()->json([
            'message' => app()->environment('local') ? $hint : 'The trade service is unavailable. Try again shortly.',
            'code' => 'gc_unavailable',
        ], 502);
    }
}
