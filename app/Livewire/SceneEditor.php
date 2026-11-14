<?php

namespace App\Livewire;

use App\Domain\Scenes\SceneOperations;
use App\Domain\Scenes\SceneRunner;
use App\Models\Device;
use App\Models\Scene;
use Livewire\Component;

/**
 * Create or edit a scene (docs/api/scenes.md). The form is built from the operations' request schemas
 * (SceneOperations::all()); saving validates with the same SceneRunner::validate the API uses.
 *
 * A step in the form: `{kind: operation|delay, operation, device_id, params: {name: "text"}, seconds}`; the texts
 * are cast to the type of the operation's input when saved (integer, number, boolean, JSON for lists and objects).
 */
class SceneEditor extends Component
{
    public const ICONS = ['play' => 'Play', 'moon' => 'Moon', 'sun' => 'Sun', 'music' => 'Music', 'couch' => 'Couch', 'martini-glass' => 'Party', 'bed' => 'Bed', 'house' => 'House', 'volume-high' => 'Volume', 'film' => 'Film'];

    public const COLORS = ['red' => '#dc2626', 'orange' => '#ea580c', 'amber' => '#d97706', 'green' => '#059669', 'sky' => '#0284c7', 'indigo' => '#4f46e5', 'violet' => '#7c3aed', 'stone' => '#57534e'];

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public ?int $sceneId = null;

    public string $name = '';

    public string $icon = '';

    public string $color = '';

    public bool $stopOnError = false;

    public bool $scheduleEnabled = false;

    public string $scheduleTime = '';

    /** @var list<int> */
    public array $scheduleDays = [];

    /** @var list<array> */
    public array $steps = [];

    /** @var array<int, list<string>> messages per step index */
    public array $stepErrors = [];

    public ?string $formError = null;

    public function mount(?int $sceneId = null): void
    {
        if ($sceneId === null) {
            return;
        }

        $scene = Scene::findOrFail($sceneId);
        $this->sceneId = $scene->id;
        $this->name = $scene->name;
        $this->icon = (string) $scene->icon;
        $this->color = (string) $scene->color;
        $this->stopOnError = $scene->stop_on_error;
        $this->scheduleEnabled = $scene->schedule_enabled;
        $this->scheduleTime = (string) $scene->schedule_time;
        $this->scheduleDays = array_map('intval', $scene->schedule_days ?? []);
        $this->steps = array_map(fn ($step) => $this->toForm($step), $scene->steps ?? []);
    }

    private function toForm(array $step): array
    {
        if (SceneRunner::isDelay($step)) {
            return ['kind' => 'delay', 'seconds' => (string) ($step['seconds'] ?? 5)];
        }

        $params = [];
        foreach (($step['params'] ?? []) as $name => $value) {
            $params[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? json_encode($value) : (string) $value);
        }

        return ['kind' => 'operation', 'operation' => $step['operation'] ?? '', 'device_id' => (string) ($step['device_id'] ?? ''), 'params' => $params];
    }

    public function addOperation(): void
    {
        $this->steps[] = ['kind' => 'operation', 'operation' => '', 'device_id' => '', 'params' => []];
    }

    public function addDelay(): void
    {
        $this->steps[] = ['kind' => 'delay', 'seconds' => '5'];
    }

    public function removeStep(int $i): void
    {
        unset($this->steps[$i]);
        $this->steps = array_values($this->steps);
        $this->stepErrors = [];
    }

    public function moveStep(int $i, int $by): void
    {
        $j = $i + $by;
        if (!isset($this->steps[$i], $this->steps[$j])) {
            return;
        }
        [$this->steps[$i], $this->steps[$j]] = [$this->steps[$j], $this->steps[$i]];
        $this->stepErrors = [];
    }

    /** Another operation has other inputs: the old values would only be refused as unknown. */
    public function updatedSteps($value, string $key): void
    {
        if (str_ends_with($key, '.operation')) {
            $i = (int) explode('.', $key)[0];
            $this->steps[$i]['params'] = [];
            if (($op = app(SceneOperations::class)->find((string) $value)) && $op['device_param'] === null) {
                $this->steps[$i]['device_id'] = '';
            }
        }
    }

    /** The step as the API stores it, the texts cast to their inputs' types. */
    private function toStep(array $form, SceneOperations $operations): array
    {
        if (($form['kind'] ?? '') === 'delay') {
            $seconds = $form['seconds'] ?? '';

            return ['type' => 'delay', 'seconds' => is_numeric($seconds) ? $seconds + 0 : null];
        }

        $operation = $operations->find((string) ($form['operation'] ?? ''));
        $step = ['operation' => $form['operation'] ?? ''];
        if (($form['device_id'] ?? '') !== '') {
            $step['device_id'] = (int) $form['device_id'];
        }

        $params = [];
        foreach (($form['params'] ?? []) as $name => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $type = $operation['inputs'][$name]['schema']['type'] ?? 'string';
            $params[$name] = match ($type) {
                'integer' => is_numeric($text) && (int) $text == $text ? (int) $text : $text,
                'number' => is_numeric($text) ? $text + 0 : $text,
                'boolean' => $text === 'true' ? true : ($text === 'false' ? false : $text),
                'array', 'object' => json_decode($text, true) ?? $text,
                default => $text,
            };
        }
        if ($params !== []) {
            $step['params'] = $params;
        }

        return $step;
    }

    public function save(SceneRunner $runner, SceneOperations $operations)
    {
        $this->stepErrors = [];
        $this->formError = null;

        $this->validate([
            'name' => 'required|string|max:120',
            'scheduleTime' => ['nullable', 'date_format:H:i'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['scheduleTime.date_format' => 'Use the format HH:MM.']);

        if ($this->scheduleEnabled && $this->scheduleTime === '') {
            $this->addError('scheduleTime', 'A schedule needs a time.');

            return;
        }

        $steps = array_map(fn ($form) => $this->toStep($form, $operations), array_values($this->steps));
        if ($errors = $runner->validate($steps)) {
            foreach ($errors as $key => $message) {
                if (preg_match('/^steps\.(\d+)\./', $key, $m)) {
                    $this->stepErrors[(int) $m[1]][] = trim(preg_replace('/^steps\.\d+\./', '', $key).': '.$message);
                } else {
                    $this->formError = $message;
                }
            }

            return;
        }

        $data = [
            'name' => trim($this->name),
            'icon' => $this->icon ?: null,
            'color' => $this->color ?: null,
            'steps' => SceneRunner::normalise($steps),
            'stop_on_error' => $this->stopOnError,
            'schedule_enabled' => $this->scheduleEnabled,
            'schedule_time' => $this->scheduleTime ?: null,
            'schedule_days' => $this->scheduleDays ? array_values(array_unique(array_map('intval', $this->scheduleDays))) : null,
        ];

        $this->sceneId ? Scene::findOrFail($this->sceneId)->update($data) : Scene::create($data);

        return $this->redirectRoute('scenes.index');
    }

    public function render(SceneOperations $operations)
    {
        $all = collect($operations->all())->sortBy('label');

        return view('livewire.scene-editor', [
            'operations' => $all,
            'devices' => Device::orderBy('device_name')->get(['id', 'device_name']),
            'icons' => self::ICONS + ($this->icon !== '' && !isset(self::ICONS[$this->icon]) ? [$this->icon => $this->icon] : []),
            'colors' => array_flip(self::COLORS) + ($this->color !== '' && !in_array($this->color, self::COLORS, true) ? [$this->color => 'Custom'] : []),
        ]);
    }
}
