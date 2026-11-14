<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A scene: a list of steps run in order through the REST API (docs/api/scenes.md); see App\Domain\Scenes\SceneRunner. */
class Scene extends Model
{
    protected $guarded = [];

    protected $casts = [
        'steps' => 'array',
        'stop_on_error' => 'boolean',
        'schedule_enabled' => 'boolean',
        'schedule_days' => 'array',
        'last_run_at' => 'datetime',
        'last_run_results' => 'array',
    ];
}
