<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An audit/evidence trail for a trade: every state change and poll result,
 * including the inventory evidence captured when a reversal is detected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['trade_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_events');
    }
};
