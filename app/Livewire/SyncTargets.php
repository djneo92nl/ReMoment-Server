<?php

namespace App\Livewire;

use App\Domain\Sync\SyncRefused;
use App\Domain\Sync\Syncs;
use App\Domain\Sync\SyncService;
use App\Domain\Tasks\UserTasks;
use App\Models\Client;
use App\Models\Media\Playlist;
use App\Models\SyncTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The Sync page under Tools (docs/architecture/offline-sync.md, docs/frontend/sync-page.md): the sync targets on nodes
 * (a USB stick, an MP3 player), their rules in a simple form, the estimate, "Use this drive", "Sync now" and the last
 * result. Admin login only; everything goes through `SyncService` and `Syncs`, the REST API's implementation.
 */
class SyncTargets extends Component
{
    public string $newName = '';

    public ?int $newNode = null;

    public ?string $editing = null;

    /** @var array<string, mixed> the rules form of the target being edited */
    public array $draft = [];

    /** @var array<string, array<string, mixed>> estimate per target id */
    public array $estimates = [];

    /** @var array<string, string> a message per target id */
    public array $notes = [];

    public ?string $error = null;

    public bool $confirm = false;

    public function create(): void
    {
        $this->admin();
        $this->error = null;

        try {
            $target = app(SyncService::class)->create(['name' => trim($this->newName), 'kind' => 'node', 'node_id' => $this->newNode]);
            $this->newName = '';
            $this->newNode = null;
            $this->edit($target->id);
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function edit(string $id): void
    {
        $this->admin();
        $target = SyncTarget::findOrFail($id);
        $rules = app(SyncService::class)->rules($target);
        $types = fn (string $t) => array_values(array_filter($rules['selections'], fn ($s) => $s['type'] === $t));

        $this->editing = $id;
        $this->draft = [
            'profile' => $rules['profile'],
            'loudness' => $rules['options']['loudness'],
            'budget_gb' => $rules['budget']['bytes'] === null ? '' : round($rules['budget']['bytes'] / 1000 ** 3, 2),
            'fill' => $rules['fill'],
            'playlists' => array_map(fn ($s) => (string) $s['id'], $types('playlist')),
            'favorites' => $types('favorites') !== [],
            'recent' => $types('recently_added')[0]['limit'] ?? '',
            'played' => $types('most_played')[0]['limit'] ?? '',
            // Album and artist selections come from the API or the apps; the form keeps them as they are.
            'other' => array_values(array_filter($rules['selections'], fn ($s) => in_array($s['type'], ['album', 'artist'], true))),
        ];
        $this->error = null;
    }

    public function save(): void
    {
        $this->admin();
        $target = SyncTarget::findOrFail($this->editing);
        $d = $this->draft;

        $selections = array_map(fn ($id) => ['type' => 'playlist', 'id' => (int) $id], $d['playlists'] ?? []);
        foreach ($d['other'] ?? [] as $s) {
            $selections[] = $s;
        }
        if (!empty($d['favorites'])) {
            $selections[] = ['type' => 'favorites'];
        }
        if (($d['recent'] ?? '') !== '') {
            $selections[] = ['type' => 'recently_added', 'limit' => (int) $d['recent']];
        }
        if (($d['played'] ?? '') !== '') {
            $selections[] = ['type' => 'most_played', 'limit' => (int) $d['played']];
        }

        $budget = ($d['budget_gb'] ?? '') === '' ? null : (int) round((float) $d['budget_gb'] * 1000 ** 3);
        $current = app(SyncService::class)->rules($target);

        try {
            app(SyncService::class)->update($target, ['rules' => [
                'selections' => $selections, 'profile' => $d['profile'], 'options' => ['loudness' => $d['loudness']] + ($current['options']['layout'] === 'flat' && count($selections) === 1 ? ['layout' => 'flat'] : []),
                'budget' => ['bytes' => $budget, 'reserve_percent' => $current['budget']['reserve_percent']], 'fill' => $d['fill'], 'max_churn_percent' => $current['max_churn_percent'],
            ]]);
            $this->editing = null;
            $this->notes[$target->id] = 'Rules saved.';
            unset($this->estimates[$target->id]);
            $this->error = null;
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->error = null;
    }

    public function estimate(string $id): void
    {
        $this->admin();
        $service = app(SyncService::class);
        $this->estimates[$id] = $service->estimate($service->rules(SyncTarget::findOrFail($id)));
    }

    public function scan(string $id): void
    {
        $this->run($id, fn (SyncTarget $t) => Syncs::scan($t), 'Reading the drive…');
    }

    public function claim(string $id): void
    {
        $this->run($id, fn (SyncTarget $t) => Syncs::claim($t), 'Marking the drive for this target…');
    }

    public function sync(string $id): void
    {
        $confirm = $this->confirm;
        $this->run($id, fn (SyncTarget $t) => Syncs::start($t, $confirm), 'Sync queued. The node picks it up in a moment.');
        $this->confirm = false;
    }

    public function cancelRun(string $id): void
    {
        $this->admin();
        Syncs::cancel(SyncTarget::findOrFail($id));
        $this->notes[$id] = 'Cancelling: the node finishes the current file first.';
    }

    public function delete(string $id): void
    {
        $this->admin();
        $target = SyncTarget::findOrFail($id);
        if ($target->job() && !$target->job()->isFinished()) {
            $this->notes[$id] = 'A job is running. Cancel it first.';

            return;
        }
        $target->delete();
        $this->editing = $this->editing === $id ? null : $this->editing;
    }

    private function run(string $id, callable $work, string $note): void
    {
        $this->admin();
        $this->error = null;
        try {
            $work(SyncTarget::findOrFail($id));
            $this->notes[$id] = $note;
        } catch (SyncRefused $e) {
            $this->notes[$id] = $e->getMessage();
        }
    }

    private function admin(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function render(): View
    {
        abort_unless(auth()->check(), 403);
        Syncs::reconcile();
        $service = app(SyncService::class);

        $targets = SyncTarget::query()->with('node')->orderBy('name')->get()->map(function (SyncTarget $t) use ($service) {
            $job = $t->job();
            $scan = Syncs::lastJob($t, 'sync.scan');
            $task = collect(UserTasks::active())->firstWhere('key', Syncs::taskKey($t));

            return [
                'model' => $t,
                'resource' => $service->resource($t),
                'running' => $job !== null && !$job->isFinished(),
                'job_type' => $job?->type,
                'job_state' => $job?->state,
                'task' => $task,
                'scan' => $scan && $scan->state === 'succeeded' ? (array) $scan->result : null,
                'scan_state' => $scan && !$scan->isFinished() ? $scan->state : ($scan?->state === 'succeeded' ? null : $scan?->error),
                'summary' => $this->rulesSummary($service->rules($t)),
            ];
        });

        return view('livewire.sync-targets', [
            'targets' => $targets,
            'playlists' => Playlist::query()->withTracksByRecency()->limit(200)->get(['playlists.id', 'playlists.name']),
            'freeNodes' => Client::query()->where('kind', 'node')->where('status', 'approved')->get()
                ->filter(fn (Client $n) => in_array('sync-target', (array) $n->roles, true) && !SyncTarget::where('node_id', $n->id)->exists()),
            'profiles' => collect(config('export.profiles'))->map(fn ($p) => $p['label'])->all(),
        ]);
    }

    /** @param array<string, mixed> $rules */
    private function rulesSummary(array $rules): string
    {
        $parts = [];
        foreach ($rules['selections'] as $s) {
            $parts[] = match ($s['type']) {
                'playlist' => 'playlist '.(Playlist::find($s['id'])?->name ?? '#'.$s['id']),
                'album', 'artist' => $s['type'].' #'.$s['id'],
                'favorites' => 'favourites',
                'recently_added' => 'newest '.($s['limit'] ?? '').($s['days'] ?? false ? " (last {$s['days']} days)" : ''),
                'most_played' => 'top '.($s['limit'] ?? 200).' played',
            };
        }

        return $parts === [] ? 'nothing selected yet' : implode(', ', $parts);
    }
}
