<?php

namespace App\Models;

use App\Domain\Scenes\SceneTriggers;
use Illuminate\Database\Eloquent\Model;

/** A scene: a list of steps run in order through the REST API (docs/api/scenes.md); see App\Domain\Scenes\SceneRunner. */
class Scene extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        // The device listeners read the triggers of all scenes from one cached index (SceneTriggers::index).
        static::saved(function (self $scene) {
            SceneTriggers::forgetIndex();
            // A run only changes its last_run_* columns: no new discovery config for that.
            if ($scene->wasRecentlyCreated || $scene->wasChanged('name')) {
                \App\Domain\HomeAssistant\Hooks::scenes();
            }
        });
        static::deleted(function () {
            SceneTriggers::forgetIndex();
            \App\Domain\HomeAssistant\Hooks::scenes();
        });
    }

    protected $casts = [
        'steps' => 'array',
        'stop_on_error' => 'boolean',
        'schedule_enabled' => 'boolean',
        'schedule_days' => 'array',
        'triggers' => 'array',
        'alarm' => 'array',
        'trigger_debounce' => 'integer',
        'last_run_at' => 'datetime',
        'last_run_results' => 'array',
    ];
}
