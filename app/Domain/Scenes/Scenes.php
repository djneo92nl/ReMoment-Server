<?php

namespace App\Domain\Scenes;

use App\Models\Scene;

/** The API shape of a scene (docs/api/scenes.md). */
class Scenes
{
    /** The list shape: what a shortcut, a widget or the scene list needs. */
    public static function item(Scene $scene): array
    {
        return [
            'id' => $scene->id,
            'name' => $scene->name,
            'icon' => $scene->icon,
            'color' => $scene->color,
            'step_count' => count($scene->steps ?? []),
            'stop_on_error' => $scene->stop_on_error,
            'schedule' => [
                'enabled' => $scene->schedule_enabled,
                'time' => $scene->schedule_time,
                'days' => $scene->schedule_days,
            ],
            'last_run' => [
                'at' => $scene->last_run_at?->toIso8601String(),
                'status' => $scene->last_run_status,
                'source' => $scene->last_run_source,
            ],
        ];
    }

    /** The detail shape: the list shape plus the steps and the per-step result of the last run. */
    public static function detail(Scene $scene): array
    {
        $item = self::item($scene);
        $item['steps'] = $scene->steps ?? [];
        $item['last_run']['results'] = $scene->last_run_results;

        return $item;
    }
}
