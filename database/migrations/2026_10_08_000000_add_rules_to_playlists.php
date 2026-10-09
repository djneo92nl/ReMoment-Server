<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Smart playlists (`source = smart`): the rules their tracks are selected by, and when that selection was last refreshed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->json('rules')->nullable()->after('images');
            $table->timestamp('refreshed_at')->nullable()->after('last_played_at');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['rules', 'refreshed_at']);
        });
    }
};
