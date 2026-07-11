<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seller's Steam refresh token authorizes the GC service to send trade offers
 * from their account on their behalf. It is stored encrypted (see the model's
 * `encrypted` cast) and never returned in API responses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('steam_refresh_token')->nullable()->after('trade_url');
            $table->timestamp('steam_selling_connected_at')->nullable()->after('steam_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['steam_refresh_token', 'steam_selling_connected_at']);
        });
    }
};
