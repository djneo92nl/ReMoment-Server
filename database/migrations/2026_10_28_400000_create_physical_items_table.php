<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Workstream 6 (docs/architecture/v1-roadmap.md, "Physical library"; docs/architecture/availability-layer.md, provider `physical`).
    // One normal migration for now: the schema review (14.3) consolidates it, so do not add "add column" migrations for this table.
    // An item stands on its own (artist, title, year), so it survives the album record it is linked to being removed.
    public function up(): void
    {
        Schema::create('physical_items', function (Blueprint $table) {
            $table->id();
            $table->string('artist');
            $table->string('title');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('artist_key'); // Normalizer::artist
            $table->string('title_key'); // Normalizer::album
            $table->string('medium', 12); // cd | tape | vinyl | minidisc | other
            $table->string('barcode', 32)->nullable();
            $table->string('catalogue_number', 64)->nullable();
            $table->string('condition', 32)->nullable();
            $table->text('notes')->nullable();
            $table->string('location')->nullable(); // free text: shelf B2, box 4, changer slot 12
            $table->date('acquired_on')->nullable();
            $table->char('release_group_mbid', 36)->nullable(); // from the barcode lookup: links the item to the discography
            $table->char('release_mbid', 36)->nullable();
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete(); // the library album, when there is one
            $table->timestamp('ripped_at')->nullable(); // workstream 7
            $table->foreignId('file_id')->nullable()->constrained()->nullOnDelete(); // the own file of the rip
            $table->timestamps();

            $table->index('barcode');
            $table->index(['artist_key', 'title_key']);
            $table->index('release_group_mbid');
            $table->index('album_id');
            $table->index('medium');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_items');
    }
};
