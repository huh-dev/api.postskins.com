<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seller's item offered for sale on the marketplace. The item's asset and
 * description are snapshotted so the listing survives an inventory re-sync;
 * `inventory_item_id` is nulled if the underlying asset is pruned (moved/sold).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_description_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('context_id');
            $table->string('market_hash_name');
            $table->string('asset_id');
            $table->bigInteger('price');
            $table->char('currency', 3);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
