<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Scene triggers and the alarm (workstream 8.1, docs/api/scenes.md). `triggers` is a JSON list of MQTT and
    // device-event triggers (App\Domain\Scenes\SceneTriggers), `trigger_debounce` the seconds after a start during
    // which further triggers of the scene are ignored, `alarm` the volume ramp that follows the scene's steps
    // (App\Domain\Scenes\SceneAlarm). A device id in either is deliberately not a foreign key, like a step's.
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->json('triggers')->nullable()->after('schedule_days');
            $table->unsignedSmallInteger('trigger_debounce')->default(10)->after('triggers');
            $table->json('alarm')->nullable()->after('trigger_debounce');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn(['triggers', 'trigger_debounce', 'alarm']);
        });
    }
};
