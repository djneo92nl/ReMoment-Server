<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A server-owned "fake multiroom" group: the leader plays, the members follow it (docs/architecture/sync-groups.md).
        Schema::create('sync_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->foreignId('leader_device_id')->unique()->constrained('devices')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('sync_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_group_id')->constrained('sync_groups')->cascadeOnDelete();
            // A device follows in at most one group.
            $table->foreignId('device_id')->unique()->constrained('devices')->cascadeOnDelete();
            // Manual trim: how far (milliseconds) this member plays ahead of (+) or behind (-) the leader.
            $table->integer('offset_ms')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_group_members');
        Schema::dropIfExists('sync_groups');
    }
};
