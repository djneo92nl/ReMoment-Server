<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('source')->nullable(); // spotify, local, deezer, tidal, etc
            $table->string('external_id')->nullable(); // spotify:playlist:{id}; null for local playlists
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->timestamps();

            $table->unique(['external_id', 'source']);
        });

        Schema::create('playlist_track', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['playlist_id', 'track_id']);
            $table->index(['playlist_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playlist_track');
        Schema::dropIfExists('playlists');
    }
};
