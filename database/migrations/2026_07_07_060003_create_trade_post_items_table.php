<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two sides of a trade post.
 *
 * An `offering` row is a concrete asset the owner holds, so it carries an
 * `asset_id` and an `inventory_item_id`. A `wanting` row is only a wish — an
 * item description with no asset behind it — so both are null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_post_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_post_id')->constrained()->cascadeOnDelete();
            $table->string('side');

            $table->foreignId('item_description_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('app_id');
            $table->unsignedInteger('context_id');
            $table->string('market_hash_name');
            $table->string('asset_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);

            $table->timestamps();

            $table->index(['trade_post_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_post_items');
    }
};
