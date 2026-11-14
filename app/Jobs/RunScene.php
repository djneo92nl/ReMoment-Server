<?php

namespace App\Jobs;

use App\Domain\Scenes\SceneRunner;
use App\Models\Scene;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Runs a scene in the background, so one with delays does not hold a request or the scheduler (docs/api/scenes.md). */
class RunScene implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420; // SceneRunner::MAX_TOTAL_DELAY plus the steps

    public function __construct(public int $sceneId, public string $source = 'api') {}

    public function handle(SceneRunner $runner): void
    {
        if ($scene = Scene::find($this->sceneId)) {
            $runner->run($scene, $this->source);
        }
    }
}
