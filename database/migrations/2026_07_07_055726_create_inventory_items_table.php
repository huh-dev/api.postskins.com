<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single owned Steam asset for a user. `asset_id` is unique per app, so an
 * item that moves/trades receives a new row. `tradable_after` is persisted so
 * the exact unlock time survives cache expiry and upstream outages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('steam_id')->index();
            $table->unsignedInteger('app_id');
            $table->unsignedInteger('context_id');
            $table->string('asset_id');
            $table->foreignId('item_description_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount')->default(1);
            $table->boolean('tradable')->default(true);
            $table->timestamp('tradable_after')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['app_id', 'asset_id']);
            $table->index(['user_id', 'app_id', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
