<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Podcasts (workstream 8.2, App\Domain\Podcasts): RSS subscriptions and their episodes. Household-wide, no accounts.
    // `feed_hash` / `guid_hash` are sha1 of the feed URL and the episode guid: the unique keys stay short on MariaDB, whatever
    // the length of a URL. An episode's bookmark lives in `playback_positions` (kind `episode`, no foreign key), see 8.3.
    public function up(): void
    {
        Schema::create('podcasts', function (Blueprint $table) {
            $table->id();
            $table->char('feed_hash', 40)->unique();
            $table->text('feed_url');
            $table->string('title', 255);
            $table->string('author', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('site_url')->nullable();
            $table->text('image_url')->nullable();
            $table->string('language', 16)->nullable();
            $table->unsignedSmallInteger('keep_episodes')->nullable(); // newest N kept; null = the server default, 0 = all
            // Conditional GET and politeness (docs/api/podcasts.md)
            $table->string('etag', 255)->nullable();
            $table->string('last_modified', 64)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('podcast_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('podcast_id')->constrained()->cascadeOnDelete();
            $table->char('guid_hash', 40);
            $table->text('guid');
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->text('enclosure_url');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('duration')->nullable(); // seconds, when the feed says
            $table->text('image_url')->nullable();
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedSmallInteger('number')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('played_at')->nullable(); // finished or marked played
            $table->timestamps();

            $table->unique(['podcast_id', 'guid_hash']);
            $table->index(['podcast_id', 'published_at']);
            $table->index('played_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('podcast_episodes');
        Schema::dropIfExists('podcasts');
    }
};
