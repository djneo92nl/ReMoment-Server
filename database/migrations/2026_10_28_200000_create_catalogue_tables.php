<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Workstream 3 (docs/architecture/availability-layer.md, "Tables"). One normal migration for now: the schema
    // review (14.3) consolidates it into the squashed set, so do not add "add column" migrations for these tables.
    // No CHECK constraints (not portable to SQLite): wanted/ignored exclusivity and "exactly one FK" are model rules.
    public function up(): void
    {
        // Sync state per library artist (the artist's MusicBrainz id stays in `metadata`).
        Schema::create('catalogue_artists', function (Blueprint $table) {
            $table->foreignId('artist_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('mbid', 36)->nullable();
            $table->string('status', 12); // ok | no_mbid | not_found | unverified | error
            $table->unsignedSmallInteger('release_group_count')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('next_sync_at')->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
        });

        // One row per MusicBrainz release-group (album or EP) of a library artist, owned or not.
        Schema::create('catalogue_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->char('release_group_mbid', 36);
            $table->string('title');
            $table->string('name_key'); // Normalizer::album(title)
            $table->string('type', 8); // album | ep
            $table->string('first_release_date', 10)->nullable(); // partial dates: 1999, 1999-05
            $table->unsignedSmallInteger('first_release_year')->nullable();
            $table->string('disambiguation')->nullable();
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete(); // promotion link
            $table->timestamp('linked_at')->nullable();
            $table->string('link_method', 12)->nullable(); // mbid | name | manual
            $table->timestamp('fulfilled_at')->nullable(); // linked album is owned: closes a wish
            $table->timestamp('wanted_at')->nullable()->index();
            $table->timestamp('ignored_at')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['artist_id', 'release_group_mbid']);
            $table->index(['artist_id', 'name_key']);
            $table->index(['artist_id', 'first_release_year']);
        });

        // What lookup providers (Spotify for unsaved albums, Deezer, Tidal, media servers) answered; on the album
        // once the library has it, on the catalogue row before.
        Schema::create('album_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_album_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 48);
            $table->string('status', 12); // available | unavailable (negative cache)
            $table->string('external_id')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->string('match', 12); // link | search | reported | manual
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->unique(['album_id', 'provider']);
            $table->unique(['catalogue_album_id', 'provider']);
            $table->index(['provider', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_availability');
        Schema::dropIfExists('catalogue_albums');
        Schema::dropIfExists('catalogue_artists');
    }
};
