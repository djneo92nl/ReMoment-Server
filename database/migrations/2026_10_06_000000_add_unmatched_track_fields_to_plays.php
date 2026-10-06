<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A play of a track that is not in the library keeps what was played as text. */
    public function up(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->string('track_name')->nullable()->after('track_id');
            $table->string('artist_name')->nullable()->after('track_name');
            $table->string('album_name')->nullable()->after('artist_name');
            $table->text('image_url')->nullable()->after('album_name');
            $table->unsignedInteger('duration')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->dropColumn(['track_name', 'artist_name', 'album_name', 'image_url', 'duration']);
        });
    }
};
