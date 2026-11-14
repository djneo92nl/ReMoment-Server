<?php

namespace App\Livewire;

use App\Domain\Scenes\SceneRunner;
use App\Models\Scene;
use Livewire\Component;

/** /scenes: the scenes of the household with Run and Delete (docs/api/scenes.md). Open to guests like presets. */
class SceneList extends Component
{
    public ?string $feedback = null;

    public bool $feedbackIsError = false;

    public function run(int $id, SceneRunner $runner): void
    {
        $scene = Scene::find($id);
        if (!$scene) {
            return;
        }

        if (SceneRunner::hasDelay($scene->steps ?? [])) {
            $runner->start($scene, 'api');
            $this->say("\"{$scene->name}\" is running in the background.");

            return;
        }

        $outcome = $runner->run($scene, 'api');
        $failed = collect($outcome['results'])->filter(fn ($r) => !$r['ok'])->count();
        match ($outcome['status']) {
            'ok' => $this->say("\"{$scene->name}\" ran."),
            'partial' => $this->say("\"{$scene->name}\": {$failed} of ".count($outcome['results']).' steps failed.', true),
            default => $this->say("\"{$scene->name}\" failed: ".($outcome['results'][0]['error'] ?? 'no step ran.'), true),
        };
    }

    public function delete(int $id): void
    {
        Scene::find($id)?->delete();
        $this->say('Scene deleted.');
    }

    private function say(string $message, bool $error = false): void
    {
        $this->feedback = $message;
        $this->feedbackIsError = $error;
    }

    public function render()
    {
        return view('livewire.scene-list', ['scenes' => Scene::orderBy('name')->orderBy('id')->get()]);
    }
}
