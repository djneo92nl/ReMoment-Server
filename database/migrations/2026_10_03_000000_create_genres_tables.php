<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One canonical genre per spelling-insensitive key ("hip hop" = "Hip-Hop" = "hiphop"); see GenreNormalizer.
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('name_key')->unique();
            $table->timestamps();
        });

        // Genres of artists, albums and tracks; `position` is the rank within the source list (0 = best).
        Schema::create('genreables', function (Blueprint $table) {
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->morphs('genreable');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('source')->nullable();

            $table->unique(['genre_id', 'genreable_type', 'genreable_id']);
        });

        // Genres already stored as metadata (artist and track `genres` keys).
        Artisan::call('library:sync-genres');
    }

    public function down(): void
    {
        Schema::dropIfExists('genreables');
        Schema::dropIfExists('genres');
    }
};
