<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a playlist was last started from the server (LibraryPlayback::playPlaylist), for the "recent first" playlist list. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->timestamp('last_played_at')->nullable()->after('images');
            $table->index('last_played_at');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropIndex(['last_played_at']);
            $table->dropColumn('last_played_at');
        });
    }
};
