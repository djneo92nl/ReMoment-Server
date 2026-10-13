<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the server computed from a file's audio. Typed columns, so smart playlists can range-query them.
        Schema::create('file_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('content_hash')->nullable(); // files.hash when analysed; a changed file starts over

            $table->float('loudness_lufs')->nullable();
            $table->float('loudness_range')->nullable();
            $table->float('true_peak')->nullable();
            $table->float('replaygain_track_gain')->nullable();
            $table->float('replaygain_album_gain')->nullable();

            $table->text('fingerprint')->nullable();
            $table->unsignedInteger('fingerprint_duration')->nullable();
            $table->string('acoustid_id')->nullable();
            $table->string('acoustid_recording_mbid')->nullable()->index();
            $table->float('acoustid_score')->nullable();

            $table->float('bpm')->nullable()->index();
            $table->string('key', 3)->nullable();
            $table->string('mode', 5)->nullable();
            $table->float('key_strength')->nullable();
            $table->float('danceability')->nullable();
            $table->float('arousal')->nullable();
            $table->float('valence')->nullable();
            $table->float('instrumental')->nullable();

            $table->json('mood_tags')->nullable();
            $table->json('genre_tags')->nullable();
            $table->json('stages')->nullable(); // {stage: {version, at}}
            $table->json('errors')->nullable(); // {stage: {message, at, attempts, version}}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_analyses');
    }
};
