<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a station was last started through the server (API, web or device card); null when never. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radio_stations', function (Blueprint $table) {
            $table->timestamp('last_played_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('radio_stations', function (Blueprint $table) {
            $table->dropIndex(['last_played_at']);
            $table->dropColumn('last_played_at');
        });
    }
};
