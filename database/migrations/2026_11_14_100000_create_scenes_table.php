<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Scenes (workstream 8.1, docs/api/scenes.md): a household-wide list of steps, each an operationId of
    // docs/api/openapi.yaml with a device and params (docs/architecture/action-layer.md, "What a scene step is").
    // Steps are JSON on the row; a step's device_id is deliberately not a foreign key (a removed device makes the
    // step fail with 404 when run, it does not delete the scene). `last_run_*` is the last result for the apps.
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon', 32)->nullable();
            $table->string('color', 16)->nullable(); // #rrggbb
            $table->json('steps');
            $table->boolean('stop_on_error')->default(false);
            $table->boolean('schedule_enabled')->default(false);
            $table->string('schedule_time', 5)->nullable(); // HH:MM, server time zone
            $table->json('schedule_days')->nullable(); // ISO weekdays 1 (Monday) to 7; null = every day
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status', 16)->nullable(); // ok | partial | failed | running
            $table->string('last_run_source', 16)->nullable(); // api | schedule | mcp
            $table->json('last_run_results')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index(['schedule_enabled', 'schedule_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
