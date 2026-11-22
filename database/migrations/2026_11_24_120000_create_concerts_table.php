<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Upcoming concerts of library artists from MusicBrainz events (roadmap 12.6, docs/api/concerts.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_mbid');
            $table->string('name');
            $table->string('type')->nullable();
            $table->date('starts_on')->index();
            $table->date('ends_on')->nullable();
            $table->string('starts_at', 5)->nullable();
            $table->string('venue')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 8, 5)->nullable();
            $table->decimal('longitude', 8, 5)->nullable();
            $table->timestamps();

            $table->unique(['artist_id', 'event_mbid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concerts');
    }
};
