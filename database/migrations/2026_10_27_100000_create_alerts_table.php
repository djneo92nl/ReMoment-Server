<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Workstream 14.1 (docs/architecture/alerts.md). One normal migration for now: the schema review (14.3)
    // consolidates it into the squashed set, so do not add "add column" migrations for this table.
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 64);
            $table->string('key', 128)->default('');
            // "kind|key" while the alert is active, NULL once resolved: a unique index over it allows one active
            // alert per (kind, key) on MariaDB and SQLite alike (both allow many NULLs in a unique index).
            $table->string('active_key', 200)->nullable()->unique();
            $table->string('severity', 16);
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('raised_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'key']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
