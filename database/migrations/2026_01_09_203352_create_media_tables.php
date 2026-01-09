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

        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('source')->nullable(); // spotify, apple, local, etc
            $table->timestamps();

            $table->unique(['name', 'source']);
        });

        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source')->nullable();
            $table->json('images')->nullable();
            $table->date('released_at')->nullable();
            $table->timestamps();

            $table->unique(['artist_id', 'name', 'source']);
        });

        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable(); // spotify id, etc
            $table->string('name');
            $table->integer('duration')->nullable();
            $table->string('source')->nullable();
            $table->json('images')->nullable();
            $table->timestamps();

            $table->unique(['external_id', 'source']);
        });

        Schema::create('metadata', function (Blueprint $table) {
            $table->id();

            // Polymorphic target
            $table->morphs('metadatable');
            // metadatable_id, metadatable_type

            // Key-value
            $table->string('key');
            $table->text('value')->nullable();

            // Hierarchy
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('metadata')
                ->cascadeOnDelete();

            // Optional typing / source
            $table->string('type')->nullable();   // string, int, json, url, date
            $table->string('source')->nullable(); // spotify, apple, musicbrainz

            $table->timestamps();

            $table->index(['key']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metadata');
        Schema::dropIfExists('albums');
        Schema::dropIfExists('artists');
        Schema::dropIfExists('tracks');
    }
};
