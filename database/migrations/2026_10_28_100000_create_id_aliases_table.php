<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Workstream 14.2 (docs/architecture/library-identity.md, "Stable ids"). One normal migration for now: the schema
    // review (14.3) consolidates it into the squashed set, so do not add "add column" migrations for this table.
    // No foreign keys on purpose: old_id points at a row that no longer exists, new_id may be removed later (a real 404).
    public function up(): void
    {
        Schema::create('id_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 16); // artist | album | track | playlist
            $table->unsignedBigInteger('old_id');
            $table->unsignedBigInteger('new_id');
            $table->timestamp('merged_at')->useCurrent();

            $table->unique(['entity', 'old_id']);
            $table->index(['entity', 'new_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_aliases');
    }
};
