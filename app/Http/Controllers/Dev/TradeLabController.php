<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Http\Resources\TradeResource;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Models\User;
use App\Services\Steam\FakeInventoryProvider;
use App\Services\Steam\InventoryProvider;
use App\Services\Steam\OfficialSteamInventoryProvider;
use App\Services\Steam\SteamApisInventoryProvider;
use App\Services\SteamInventorySync;
use App\Services\Trading\TradeService;
use App\Services\Trading\TradeVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * A LOCAL-ONLY harness that drives the full P2P trade flow through the real
 * services. The seller is a real Steam account whose public inventory is pulled
 * via the normal inventory sync; the buyer is a simulated account whose receipt
 * and reversal are faked (a real Steam trade can't execute locally). Powers the
 * `/trade-lab` page in the frontend.
 */
class TradeLabController extends Controller
{
    private const BUYER_STEAM_ID = '76561199000000002';

    private const BUYER_START_BALANCE = 100_000; // minor units

    private const SELLER_CACHE_KEY = 'trade_lab_seller_id';

    private const STEAM_IMAGE_BASE = 'https://community.cloudflare.steamstatic.com/economy/image/';

    public function __construct(
        private readonly TradeService $trades,
        private readonly SteamInventorySync $sync,
        private readonly TradeVerifier $verifier,
    ) {
        abort_unless(app()->environment('local'), 404);
    }

    /**
     * Reset the lab: fresh buyer with balance, no seller, no trades.
     */
    public function reset(): JsonResponse
    {
        Trade::query()->delete();

        $buyer = $this->seedBuyer();
        InventoryItem::where('user_id', $buyer->id)->delete();
        FakeInventoryProvider::set(self::BUYER_STEAM_ID, 'ok', []);

        Cache::forget(self::SELLER_CACHE_KEY);

        return $this->snapshot();
    }

    /**
     * Pull a real Steam account's public inventory and make it the seller's
     * offerable items, using the normal (non-fake) inventory provider.
     */
    public function syncSeller(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'steam_id' => ['required', 'string', 'regex:/^\d{17}$/'],
        ]);

        $seller = User::firstOrCreate(
            ['steam_id' => $validated['steam_id']],
            ['name' => 'Lab Seller '.$validated['steam_id']],
        );

        // Keep the lab usable across reversal tests.
        $seller->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        Cache::forever(self::SELLER_CACHE_KEY, $seller->id);

        $result = $this->realProvider()->fetch($seller->steam_id, 730, 2);

        if ($result->status === 'ok') {
            $this->sync->sync($seller, 730, 2, $result->items);
        }

        return $this->snapshot($result->status);
    }

    /**
     * Seed a demo seller inventory with no external dependency, so the flow can
     * be exercised without a configured Steam inventory driver or real account.
     */
    public function demoSeller(): JsonResponse
    {
        $seller = User::firstOrCreate(
            ['steam_id' => '76561199000000001'],
            ['name' => 'Demo Seller'],
        );

        $seller->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        Cache::forever(self::SELLER_CACHE_KEY, $seller->id);

        $this->sync->sync($seller, 730, 2, $this->demoItems());

        return $this->snapshot('ok');
    }

    /**
     * A small catalog of normalized item rows for the demo seller.
     *
     * @return array<int, array<string, mixed>>
     */
    private function demoItems(): array
    {
        $catalog = [
            ['AWP | Dragon Lore', 'Covert Sniper Rifle', '9001'],
            ['AK-47 | Redline', 'Classified Rifle', '9002'],
            ['M4A4 | Howl', 'Contraband Rifle', '9003'],
            ['Glock-18 | Fade', 'Restricted Pistol', '9004'],
            ['Desert Eagle | Blaze', 'Restricted Pistol', '9005'],
        ];

        return collect($catalog)->map(fn (array $item, int $i): array => [
            'asset_id' => 'demo-'.($i + 1),
            'class_id' => $item[2],
            'instance_id' => '0',
            'amount' => 1,
            'name' => $item[0],
            'market_name' => $item[0].' (Factory New)',
            'market_hash_name' => $item[0].' (Factory New)',
            'type' => $item[1],
            'tradable' => true,
            'tradable_after' => null,
            'trade_hold_days' => 7,
            'marketable' => true,
            'icon_url' => null,
        ])->all();
    }

    /**
     * Create the offer: the buyer buys one of the seller's synced items.
     */
    public function buy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'price' => ['required', 'integer', 'min:1'],
        ]);

        $seller = $this->seller();
        $buyer = $this->buyer();

        if ($seller === null || $buyer === null) {
            return response()->json(['message' => 'Load a seller inventory and reset the buyer first.'], 422);
        }

        if ($this->currentTrade()?->status->isActive()) {
            return response()->json(['message' => 'A trade is already in progress — reset to start a new offer.'], 409);
        }

        $item = InventoryItem::with('itemDescription')
            ->where('id', $validated['inventory_item_id'])
            ->where('user_id', $seller->id)
            ->first();

        if ($item === null) {
            return response()->json(['message' => 'Pick one of the seller\'s items.'], 422);
        }

        if ($buyer->ensureWallet()->balance < $validated['price']) {
            return response()->json(['message' => 'The buyer\'s balance is too low for that price.'], 422);
        }

        $this->trades->open($buyer, $item, $validated['price']);

        return $this->snapshot();
    }

    /**
     * Simulate the buyer receiving the item (trade-locked), then verify.
     */
    public function simulateReceived(): JsonResponse
    {
        $trade = $this->currentTrade();

        if ($trade !== null) {
            FakeInventoryProvider::set(self::BUYER_STEAM_ID, 'ok', [$this->receivedItemRow($trade)]);
            $this->runVerification();
        }

        return $this->snapshot();
    }

    /**
     * Simulate a reversal: the item leaves the buyer's inventory, then verify.
     */
    public function simulateReversal(): JsonResponse
    {
        FakeInventoryProvider::set(self::BUYER_STEAM_ID, 'ok', []);
        $this->runVerification();

        return $this->snapshot();
    }

    /**
     * Current lab state; also re-verifies so trades progress as time passes.
     */
    public function state(): JsonResponse
    {
        if ($this->currentTrade()?->status->isActive()) {
            $this->runVerification();
        }

        return $this->snapshot();
    }

    /**
     * Read the buyer's (fake) inventory and verify the current trade directly,
     * bypassing the scheduler's due-time filter so a lab click takes effect now.
     */
    private function runVerification(): void
    {
        $buyer = $this->buyer();
        $trade = $this->currentTrade();

        if ($buyer === null || $trade === null || ! $trade->status->isActive()) {
            return;
        }

        $result = app(FakeInventoryProvider::class)->fetch($buyer->steam_id, $trade->app_id, $trade->context_id);

        if ($result->status !== 'ok') {
            return;
        }

        $this->sync->sync($buyer, $trade->app_id, $trade->context_id, $result->items);
        $this->verifier->verify($trade);
    }

    /**
     * The real inventory provider, bypassing any fake binding.
     */
    private function realProvider(): InventoryProvider
    {
        return match (config('services.steam_inventory.driver')) {
            'official' => app(OfficialSteamInventoryProvider::class),
            default => app(SteamApisInventoryProvider::class),
        };
    }

    /**
     * The normalized inventory row for a received, trade-locked copy of the item.
     *
     * @return array<string, mixed>
     */
    private function receivedItemRow(Trade $trade): array
    {
        $description = $trade->itemDescription;

        return [
            'asset_id' => 'received-'.$trade->id,
            'class_id' => $description->classid,
            'instance_id' => $description->instanceid,
            'amount' => 1,
            'name' => $description->name,
            'market_name' => $description->market_name,
            'market_hash_name' => $description->market_hash_name,
            'type' => $description->type,
            'tradable' => false,
            'tradable_after' => CarbonImmutable::now()->addDays((int) config('trades.protection_hold_days')),
            'trade_hold_days' => $description->trade_hold_days,
            'marketable' => (bool) $description->marketable,
            'icon_url' => $description->icon_url,
        ];
    }

    private function seedBuyer(): User
    {
        $buyer = User::updateOrCreate(
            ['steam_id' => self::BUYER_STEAM_ID],
            [
                'name' => 'Lab Buyer',
                'trade_url' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=lab',
                'suspended_at' => null,
                'suspension_reason' => null,
            ],
        );

        $buyer->ensureWallet()->forceFill(['balance' => self::BUYER_START_BALANCE, 'locked_balance' => 0])->save();

        return $buyer;
    }

    private function seller(): ?User
    {
        $id = Cache::get(self::SELLER_CACHE_KEY);

        return $id ? User::find($id) : null;
    }

    private function buyer(): ?User
    {
        return User::where('steam_id', self::BUYER_STEAM_ID)->first();
    }

    private function currentTrade(): ?Trade
    {
        return Trade::query()->latest('id')->first();
    }

    /**
     * The seller's synced items, offerable as listings.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listings(?User $seller): array
    {
        if ($seller === null) {
            return [];
        }

        return InventoryItem::with('itemDescription')
            ->where('user_id', $seller->id)
            ->where('app_id', 730)
            ->where('context_id', 2)
            ->orderByDesc('tradable')
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->map(fn (InventoryItem $item): array => [
                'inventory_item_id' => $item->id,
                'name' => $item->itemDescription->name,
                'market_hash_name' => $item->itemDescription->market_hash_name,
                'icon_url' => $this->resolveIconUrl($item->itemDescription->icon_url),
                'tradable' => $item->tradable,
            ])
            ->all();
    }

    private function snapshot(?string $sellerInventoryStatus = null): JsonResponse
    {
        $seller = $this->seller();
        $buyer = $this->buyer();
        $trade = $this->currentTrade();
        $trade?->load(['seller', 'buyer', 'itemDescription', 'events']);

        return response()->json([
            'seller' => $this->userState($seller),
            'buyer' => $this->userState($buyer),
            'listings' => $this->listings($seller),
            'seller_inventory_status' => $sellerInventoryStatus,
            'trade' => $trade ? new TradeResource($trade) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userState(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $wallet = $user->ensureWallet();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'steam_id' => $user->steam_id,
            'suspended' => $user->isSuspended(),
            'suspension_reason' => $user->suspension_reason,
            'balance' => $wallet->balance,
            'locked_balance' => $wallet->locked_balance,
            'currency' => $wallet->currency,
        ];
    }

    private function resolveIconUrl(?string $iconUrl): ?string
    {
        if (! $iconUrl) {
            return null;
        }

        return str_starts_with($iconUrl, 'http') ? $iconUrl : self::STEAM_IMAGE_BASE.$iconUrl;
    }
}
