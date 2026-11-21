<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Workstream 18.3 (docs/architecture/offline-sync.md): a sync target (a USB stick on a node, the iOS app's offline
    // library) with its rules, and the shared cache of prepared files (transcodes made once per fingerprint).
    // Ordinary migration for now: the schema review (14.3) squashes it, so do not add "add column" migrations for these tables.
    // No per-user fields: a target belongs to the household.
    public function up(): void
    {
        Schema::create('sync_targets', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID: the id written into the marker on the target
            $table->string('name', 100);
            $table->string('kind', 12); // app | node (helper later)
            $table->string('client_ref', 64)->nullable()->unique(); // app: the install id the app keeps
            $table->foreignId('node_id')->nullable()->constrained('clients')->nullOnDelete(); // node: the node whose volume it is
            $table->json('rules')->nullable();
            $table->unsignedInteger('rules_version')->default(1);
            $table->json('last_report')->nullable(); // what the executor reported: applied/failed counts, owned bytes, free space
            $table->timestamp('last_report_at')->nullable();
            $table->unsignedBigInteger('owned_bytes')->default(0);
            $table->unsignedInteger('owned_count')->default(0);
            $table->json('last_run')->nullable(); // the latest node run: job id, state, summary, error
            $table->string('active_job_id', 26)->nullable(); // the node job running now (one apply per target)
            $table->timestamps();
        });

        Schema::create('sync_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 40)->unique(); // sha1 of source hash + profile + codec + gain ... (SyncFingerprints)
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('state', 12)->default('preparing')->index(); // preparing | ready | failed
            $table->string('extension', 8);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_artifacts');
        Schema::dropIfExists('sync_targets');
    }
};
