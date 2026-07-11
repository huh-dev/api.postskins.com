<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The concrete assets an offer moves in each direction.
 *
 * `from_offerer` rows come from the offerer's inventory; `from_poster` rows are
 * the subset of the post's offered assets the offerer wants in return. Both
 * carry a real asset id, so the accepted offer alone describes the whole trade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_offer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_offer_id')->constrained()->cascadeOnDelete();
            $table->string('side');

            $table->foreignId('item_description_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('app_id');
            $table->unsignedInteger('context_id');
            $table->string('market_hash_name');
            $table->string('asset_id');

            $table->timestamps();

            $table->index(['trade_offer_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_offer_items');
    }
};
