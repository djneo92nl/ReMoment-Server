<?php

namespace App\Livewire;

use App\Domain\Device\State;
use App\Domain\Profile\Profile;
use App\Domain\Scenes\SceneAlarm;
use App\Domain\Scenes\SceneOperations;
use App\Domain\Scenes\SceneRunner;
use App\Domain\Scenes\SceneTriggers;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\RadioStation;
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

    /** Inputs that take a record id, picked by name: input name => list. Anything else stays a plain field. */
    public const PICKERS = ['playlist_id' => 'playlists', 'playlist' => 'playlists', 'station' => 'stations'];

    /** Inputs that take a library record found by search: input name => album | artist | track (Full library only). */
    public const SEARCH_PICKERS = ['album_id' => 'album', 'artist_id' => 'artist', 'track_id' => 'track'];

    public ?int $sceneId = null;

    /** @var array<int, array<string, string>> search text per step and input */
    public array $pickerSearch = [];

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

    /** @var list<array<string, string>> `{type: mqtt, topic, payload}` or `{type: device, device_id, event, value}` as form texts */
    public array $triggers = [];

    public string $triggerDebounce = '10';

    /** @var array<int, list<string>> messages per trigger index */
    public array $triggerErrors = [];

    public bool $alarmEnabled = false;

    public string $alarmDeviceId = '';

    public string $alarmMinutes = '20';

    public string $alarmStart = '5';

    public string $alarmTarget = '40';

    /** @var list<string> */
    public array $alarmErrors = [];

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
        $this->triggers = array_map(fn ($t) => $t['type'] === 'mqtt'
            ? ['type' => 'mqtt', 'topic' => $t['topic'], 'payload' => (string) ($t['payload'] ?? '')]
            : ['type' => 'device', 'device_id' => (string) $t['device_id'], 'event' => $t['event'], 'value' => (string) ($t['value'] ?? '')], $scene->triggers ?? []);
        $this->triggerDebounce = (string) ($scene->trigger_debounce ?? config('scenes.triggers.debounce_default'));
        if ($alarm = $scene->alarm) {
            $this->alarmEnabled = true;
            $this->alarmDeviceId = (string) $alarm['device_id'];
            $this->alarmMinutes = (string) $alarm['minutes'];
            $this->alarmStart = (string) $alarm['start_volume'];
            $this->alarmTarget = (string) $alarm['target_volume'];
        }
    }

    public function addTrigger(string $type): void
    {
        $this->triggers[] = $type === 'mqtt'
            ? ['type' => 'mqtt', 'topic' => '', 'payload' => '']
            : ['type' => 'device', 'device_id' => '', 'event' => 'state', 'value' => State::Playing->value];
        $this->triggerErrors = [];
    }

    public function removeTrigger(int $i): void
    {
        unset($this->triggers[$i]);
        $this->triggers = array_values($this->triggers);
        $this->triggerErrors = [];
    }

    /** Another event has other values: a state is picked from a list, a source is a name. */
    public function updatedTriggers($value, string $key): void
    {
        if (str_ends_with($key, '.event')) {
            $i = (int) explode('.', $key)[0];
            $this->triggers[$i]['value'] = $value === 'state' ? State::Playing->value : '';
        }
    }

    /** The triggers as the API stores them, device ids cast, empty optional texts left out. */
    private function triggersForSave(): array
    {
        return array_map(function ($t) {
            if (($t['type'] ?? '') === 'mqtt') {
                return array_filter(['type' => 'mqtt', 'topic' => trim($t['topic'] ?? ''), 'payload' => ($t['payload'] ?? '') === '' ? null : $t['payload']], fn ($v) => $v !== null);
            }

            return array_filter([
                'type' => 'device',
                'device_id' => ctype_digit((string) ($t['device_id'] ?? '')) ? (int) $t['device_id'] : null,
                'event' => $t['event'] ?? null,
                'value' => trim((string) ($t['value'] ?? '')) === '' ? null : trim($t['value']),
            ], fn ($v) => $v !== null);
        }, array_values($this->triggers));
    }

    private function alarmForSave(): ?array
    {
        if (!$this->alarmEnabled) {
            return null;
        }
        $int = fn (string $v) => is_numeric($v) && (int) $v == $v ? (int) $v : null;

        return [
            'device_id' => ctype_digit($this->alarmDeviceId) ? (int) $this->alarmDeviceId : null,
            'minutes' => $int($this->alarmMinutes),
            'start_volume' => $int($this->alarmStart),
            'target_volume' => $int($this->alarmTarget),
        ];
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

    /** The name a picked record is shown with, or null when it no longer exists. */
    public function pickLabel(string $kind, int $id): ?string
    {
        return match ($kind) {
            'album' => ($r = Album::with('artist:id,name')->find($id)) ? $r->name.($r->artist ? ' — '.$r->artist->name : '') : null,
            'artist' => Artist::find($id)?->name,
            default => ($r = Track::with('artist:id,name')->find($id)) ? $r->name.($r->artist ? ' — '.$r->artist->name : '') : null,
        };
    }

    /**
     * Up to 20 records whose name contains the text (artists: their name; albums and tracks also their artist's).
     *
     * @return array<int, string> id => label
     */
    public function pickSearch(string $kind, string $text): array
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return [];
        }
        $like = '%'.addcslashes($text, '%_\\').'%';

        if ($kind === 'artist') {
            return Artist::where('name', 'like', $like)->orderBy('name')->limit(20)->pluck('name', 'id')->all();
        }

        $model = $kind === 'album' ? Album::class : Track::class;

        return $model::with('artist:id,name')
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhereHas('artist', fn ($a) => $a->where('name', 'like', $like)))
            ->orderBy('name')->limit(20)->get()
            ->mapWithKeys(fn ($r) => [$r->id => $r->name.($r->artist ? ' — '.$r->artist->name : '')])->all();
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
        $this->pickerSearch = [];
        $this->stepErrors = [];
    }

    public function moveStep(int $i, int $by): void
    {
        $j = $i + $by;
        if (!isset($this->steps[$i], $this->steps[$j])) {
            return;
        }
        [$this->steps[$i], $this->steps[$j]] = [$this->steps[$j], $this->steps[$i]];
        $this->pickerSearch = [];
        $this->stepErrors = [];
    }

    /** Another operation has other inputs: the old values would only be refused as unknown. */
    public function updatedSteps($value, string $key): void
    {
        if (str_ends_with($key, '.operation')) {
            $i = (int) explode('.', $key)[0];
            $this->steps[$i]['params'] = [];
            $this->pickerSearch = [];
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

        $this->triggerErrors = [];
        $this->alarmErrors = [];
        $triggers = $this->triggersForSave();
        $alarm = $this->alarmForSave();
        $debounce = is_numeric($this->triggerDebounce) && (int) $this->triggerDebounce == $this->triggerDebounce ? (int) $this->triggerDebounce : null;
        $found = false;
        foreach (SceneTriggers::validate($triggers) as $key => $message) {
            $found = true;
            if (preg_match('/^triggers\.(\d+)\.(.+)$/', $key, $m)) {
                $this->triggerErrors[(int) $m[1]][] = $m[2].': '.$message;
            } else {
                $this->formError = $message;
            }
        }
        if ($triggers !== [] && ($error = SceneTriggers::debounceErrors($debounce))) {
            $found = true;
            $this->addError('triggerDebounce', $error['trigger_debounce']);
        }
        foreach (SceneAlarm::validate($alarm) as $key => $message) {
            $found = true;
            $this->alarmErrors[] = $message;
        }
        if ($found) {
            return;
        }

        $data = [
            'triggers' => SceneTriggers::normalise($triggers),
            'trigger_debounce' => $triggers === [] ? (int) config('scenes.triggers.debounce_default') : $debounce,
            'alarm' => SceneAlarm::normalise($alarm),
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
            'pickLists' => [
                // Control has no library, so no playlists: the field stays a plain id there.
                'playlists' => Profile::allows('library') ? Playlist::orderBy('name')->limit(1000)->pluck('name', 'id')->all() : null,
                'stations' => RadioStation::orderBy('name')->limit(1000)->pluck('name', 'id')->all(),
            ],
            'searchPickers' => Profile::allows('library'),
            'devices' => Device::orderBy('device_name')->get(['id', 'device_name']),
            'states' => array_map(fn (State $state) => $state->value, State::cases()),
            'icons' => self::ICONS + ($this->icon !== '' && !isset(self::ICONS[$this->icon]) ? [$this->icon => $this->icon] : []),
            'colors' => array_flip(self::COLORS) + ($this->color !== '' && !in_array($this->color, self::COLORS, true) ? [$this->color => 'Custom'] : []),
        ]);
    }
}
