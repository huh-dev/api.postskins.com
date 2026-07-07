<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalized, shared item metadata keyed by app + classid + instanceid.
 *
 * A single row is referenced by every user's assets of the same item, so the
 * heavy description payload (name, image, type) is stored once, not per asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_descriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('app_id');
            $table->string('classid');
            $table->string('instanceid')->default('0');
            $table->string('name')->nullable();
            $table->string('market_name')->nullable();
            $table->string('market_hash_name')->nullable();
            $table->string('type')->nullable();
            $table->string('icon_url')->nullable();
            $table->boolean('marketable')->default(false);
            $table->unsignedSmallInteger('trade_hold_days')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'classid', 'instanceid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_descriptions');
    }
};
