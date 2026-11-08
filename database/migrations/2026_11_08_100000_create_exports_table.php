<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Workstream 13 (docs/architecture/export.md): one export request of tracks as a zip for an MP3 player or a car radio.
    // One normal migration for now: the schema review (14.3) consolidates it, so do not add "add column" migrations for this table.
    // No per-user fields: there is one shared admin.
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('selection_type', 12); // album | playlist | artist | tracks
            $table->unsignedBigInteger('selection_id')->nullable(); // null for `tracks`
            $table->json('track_ids')->nullable(); // the track list, in export order (type `tracks`)
            $table->string('profile', 32);
            $table->json('options')->nullable(); // layout (tree|flat), playlist (bool) ...
            $table->string('status', 12)->default('queued')->index(); // queued | running | done | failed | expired
            $table->unsignedInteger('tracks')->default(0); // from the plan
            $table->unsignedInteger('copied')->default(0);
            $table->unsignedInteger('transcoded')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedBigInteger('estimated_bytes')->default(0);
            $table->unsignedSmallInteger('planned_parts')->default(0);
            $table->unsignedInteger('files_total')->default(0); // progress: files done of total
            $table->unsignedInteger('files_done')->default(0);
            $table->unsignedBigInteger('bytes')->default(0); // what the finished parts hold
            $table->json('skipped')->nullable(); // [{ track_id, name, reason }]
            $table->json('part_files')->nullable(); // [{ name, size, files }] under exports/{public_id}/
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
