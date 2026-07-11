<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trade post: what its owner puts up, what they want back, and how much cash
 * either side adds to balance the difference. A cash-only sale is the degenerate
 * case — items offered, nothing wanted but `want_cash`.
 *
 * The owner is always the party that sends the resulting Steam trade offer, so
 * posting requires a connected Steam session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('app_id');
            $table->unsignedInteger('context_id');

            $table->bigInteger('offer_cash')->default(0);
            $table->bigInteger('want_cash')->default(0);
            $table->char('currency', 3);

            // "Open to offers": the wanting side is unspecified.
            $table->boolean('wants_anything')->default(false);
            $table->string('note')->nullable();

            $table->string('status')->default('open');

            // Points at trade_offers, which is created after this table. Kept as a
            // plain indexed column rather than a foreign key to avoid a circular
            // constraint (trade_offers -> trade_posts -> trade_offers).
            $table->unsignedBigInteger('accepted_offer_id')->nullable()->index();

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_posts');
    }
};
