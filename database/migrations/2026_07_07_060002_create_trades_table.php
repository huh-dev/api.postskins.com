<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A peer-to-peer trade: the seller sends a skin directly to the buyer, whose
 * payment is held until Steam's trade-protection window passes with no reversal.
 * Asset ids change when an item moves, so delivery is verified by matching
 * `market_hash_name` against the buyer's inventory, not by asset id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('item_description_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('app_id');
            $table->unsignedInteger('context_id');
            $table->string('market_hash_name');
            $table->string('asset_id_listed');
            $table->string('asset_id_received')->nullable();

            $table->bigInteger('price');
            $table->char('currency', 3);

            $table->string('steam_tradeoffer_id')->nullable();
            $table->string('status')->default('pending_delivery');
            $table->boolean('escrow')->default(false);

            $table->timestamp('protection_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_poll_at']);
            $table->index(['status', 'protection_expires_at']);
            $table->index(['buyer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
