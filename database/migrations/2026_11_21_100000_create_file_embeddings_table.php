<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Discogs-EffNet embedding of a file (the mean over its patches), the source of truth for "similar tracks"
        // (docs/architecture/similar-tracks.md). Its own table so the file_analyses rows that every analysis query reads
        // stay small: 1280 float32 numbers is about 5 kB per file. Derived search indexes are rebuilt from here.
        Schema::create('file_embeddings', function (Blueprint $table) {
            $table->foreignId('file_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('model', 60);                      // which network made it; vectors of different models never meet
            $table->unsignedSmallInteger('dimensions');
            $table->binary('vector');                         // little-endian float32, L2-normalised: cosine similarity is the dot product
            $table->string('content_hash')->nullable();       // files.hash when computed; a changed file's embedding is ignored until recomputed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_embeddings');
    }
};
