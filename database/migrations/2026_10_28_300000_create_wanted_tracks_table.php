<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Workstream 3 (docs/architecture/availability-layer.md, "Wished songs"). One normal migration for now: the schema
    // review (14.3) consolidates it, so do not add "add column" migrations for this table.
    // A song someone wants to buy or rip: from the play history or a radio station, often with no album and not in the library.
    public function up(): void
    {
        Schema::create('wanted_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('artist');
            $table->string('title');
            $table->string('album')->nullable();
            $table->text('image_url')->nullable();
            $table->string('artist_key'); // Normalizer::artist(artist)
            $table->string('title_key'); // Normalizer::track(title)
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete(); // the library track, when there is one
            $table->string('source', 12)->default('manual'); // history | radio | manual
            $table->timestamp('wanted_at');
            $table->timestamp('fulfilled_at')->nullable(); // the library track has an own file or a DLNA copy
            $table->timestamps();

            $table->unique(['artist_key', 'title_key']);
            $table->index('wanted_at');
            $table->index('track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wanted_tracks');
    }
};
