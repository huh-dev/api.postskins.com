<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One asset moving in one direction within a trade.
 *
 * `giver_id` and `receiver_id` are denormalized from the trade so the verifier
 * never has to reason about which side a leg belongs to. Asset ids change when
 * an item moves, so `asset_id_received` is captured by matching the leg's
 * `market_hash_name` in the receiver's inventory once it lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->string('side');

            $table->foreignId('giver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('item_description_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('app_id');
            $table->unsignedInteger('context_id');
            $table->string('market_hash_name');

            $table->string('asset_id_sent');
            $table->string('asset_id_received')->nullable();
            $table->timestamp('received_seen_at')->nullable();

            $table->timestamps();

            $table->index(['trade_id', 'side']);
            $table->index(['receiver_id', 'app_id']);
            $table->index(['giver_id', 'app_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_items');
    }
};
