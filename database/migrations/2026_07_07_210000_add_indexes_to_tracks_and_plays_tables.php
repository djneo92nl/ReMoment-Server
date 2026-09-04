<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->index('album_id');
            $table->index('artist_id');
        });

        Schema::table('plays', function (Blueprint $table) {
            $table->index('track_id');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropIndex(['album_id']);
            $table->dropIndex(['artist_id']);
        });

        Schema::table('plays', function (Blueprint $table) {
            $table->dropIndex(['track_id']);
        });
    }
};
