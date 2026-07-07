<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The user's Steam trade offer URL (partner + token) — needed to
            // direct an incoming trade to them as a buyer.
            $table->string('trade_url')->nullable()->after('avatar');

            // Set when a reversal is detected; blocks new trades pending review.
            $table->timestamp('suspended_at')->nullable()->after('trade_url');
            $table->string('suspension_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['trade_url', 'suspended_at', 'suspension_reason']);
        });
    }
};
