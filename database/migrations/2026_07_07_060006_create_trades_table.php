<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An executed peer-to-peer trade. The initiator (the post owner) sends one
 * atomic Steam offer carrying both parties' items; any cash is held until
 * Steam's trade-protection window passes with no reversal.
 *
 * Items live in `trade_items`, one row per leg — a trade can move several assets
 * in both directions. Cash is already netted into a single directional transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trade_post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_offer_id')->nullable()->constrained()->nullOnDelete();

            // The initiator sends the Steam offer; the counterparty accepts it.
            $table->foreignId('initiator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('counterparty_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('app_id');
            $table->unsignedInteger('context_id');

            $table->bigInteger('cash_amount')->default(0);
            $table->foreignId('cash_payer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_payee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('currency', 3);

            $table->string('steam_tradeoffer_id')->nullable();
            $table->string('status')->default('pending_delivery');
            $table->boolean('escrow')->default(false);

            // A reversed swap can't be blamed from inventory alone: flag for a human.
            $table->boolean('needs_review')->default(false);

            $table->timestamp('protection_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_poll_at']);
            $table->index(['status', 'protection_expires_at']);
            $table->index(['initiator_id', 'status']);
            $table->index(['counterparty_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
