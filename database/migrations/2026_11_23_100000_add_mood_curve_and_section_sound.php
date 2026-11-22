<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mood over time (workstream 16.3, on-demand `mood` kind): { method, service_version, window, arousal: [0-100],
        // valence: [0-100] }; null = never computed. `section_sound` (16.5, kind `section_sound`) is the manifest of the
        // sections whose sound was embedded: { model, items: [{start, end}] }; the vectors are in section_embeddings.
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->json('mood_curve')->nullable();
            $table->json('section_sound')->nullable();
        });

        // One Discogs-EffNet embedding per section of a file (the mean over the patches inside it), for "sections that
        // sound like this one" (docs/architecture/section-similarity.md). Only tracks someone asked for have rows, so the
        // table stays small; vectors are L2-normalised little-endian float32 like file_embeddings.
        Schema::create('section_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');         // index of the section in file_analyses.sections.items
            $table->float('start');
            $table->float('end');
            $table->string('label', 4)->nullable();           // the repetition letter, for display
            $table->string('model', 60);
            $table->binary('vector');
            $table->string('content_hash')->nullable();       // files.hash when computed; a changed file's rows are ignored
            $table->unique(['file_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_embeddings');
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->dropColumn(['mood_curve', 'section_sound']);
        });
    }
};
