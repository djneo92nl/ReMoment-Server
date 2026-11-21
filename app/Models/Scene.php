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
        static::saved(fn () => SceneTriggers::forgetIndex());
        static::deleted(fn () => SceneTriggers::forgetIndex());
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
