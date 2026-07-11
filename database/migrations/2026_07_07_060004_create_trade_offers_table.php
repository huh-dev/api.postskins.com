<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A counter-offer against a trade post.
 *
 * Cash is stored already netted: one non-negative `cash_amount` plus which side
 * pays it. That keeps the ledger a single directional transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offerer_id')->constrained('users')->cascadeOnDelete();

            $table->bigInteger('cash_amount')->default(0);
            // 'offerer' | 'poster'; null when cash_amount is 0.
            $table->string('cash_payer')->nullable();
            $table->char('currency', 3);

            $table->string('message')->nullable();
            $table->string('status')->default('pending');

            // Set when accepted. Plain indexed column, not a foreign key: the
            // trades table is created after this one and points back at it.
            $table->unsignedBigInteger('trade_id')->nullable()->index();

            $table->timestamps();

            $table->index(['trade_post_id', 'status']);
            $table->index(['offerer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_offers');
    }
};
