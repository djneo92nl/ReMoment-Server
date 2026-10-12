<?php

namespace App\Livewire;

use App\Domain\Storage\FileStore;
use App\Domain\Storage\StorageTasks;
use App\Models\StorageLocation;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** /settings/storage: where the audio files live (local folder or S3), and the queued scan/import/reorganize tasks. */
class StorageManager extends Component
{
    /** `new` while adding, a location id while editing. */
    public int|string|null $editing = null;

    public string $name = '';

    public string $driver = 'local';

    public string $role = StorageLocation::ROLE_LIBRARY;

    public string $root = '';

    public string $bucket = '';

    public string $region = '';

    public string $endpoint = '';

    public string $prefix = '';

    public string $key = '';

    public string $secret = '';

    public bool $pathStyle = false;

    public function newLocation(): void
    {
        $this->reset(['name', 'driver', 'role', 'root', 'bucket', 'region', 'endpoint', 'prefix', 'key', 'secret', 'pathStyle']);
        $this->resetErrorBag();
        $this->editing = 'new';
    }

    public function edit(int $id): void
    {
        $location = StorageLocation::findOrFail($id);
        $config = $location->config ?? [];

        $this->resetErrorBag();
        $this->editing = $id;
        $this->name = $location->name;
        $this->driver = $location->driver;
        $this->role = $location->role;
        $this->root = $config['root'] ?? '';
        $this->bucket = $config['bucket'] ?? '';
        $this->region = $config['region'] ?? '';
        $this->endpoint = $config['endpoint'] ?? '';
        $this->prefix = $config['prefix'] ?? '';
        $this->pathStyle = (bool) ($config['use_path_style_endpoint'] ?? false);
        $this->key = '';
        $this->secret = ''; // never sent to the browser; left blank it is kept
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $existing = is_int($this->editing) ? StorageLocation::findOrFail($this->editing) : null;

        $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('storage_locations', 'name')->ignore($existing?->id)],
            'driver' => ['required', Rule::in(StorageLocation::DRIVERS)],
            'role' => ['required', Rule::in([StorageLocation::ROLE_LIBRARY, StorageLocation::ROLE_INBOX])],
            'root' => ['required_if:driver,local', 'nullable', 'string'],
            'bucket' => ['required_if:driver,s3', 'nullable', 'string'],
        ]);

        if ($this->driver === 'local' && !is_dir($this->root)) {
            $this->addError('root', 'This folder does not exist on the server.');

            return;
        }

        $old = $existing?->config ?? [];
        $config = $this->driver === 'local'
            ? ['root' => rtrim($this->root, '/') ?: '/']
            : array_filter([
                'bucket' => $this->bucket,
                'region' => $this->region ?: null,
                'endpoint' => $this->endpoint ?: null,
                'prefix' => $this->prefix ?: null,
                'use_path_style_endpoint' => $this->pathStyle,
                'key' => $this->key ?: ($old['key'] ?? null),
                'secret' => $this->secret ?: ($old['secret'] ?? null),
            ], fn ($v) => $v !== null);

        $attributes = ['name' => trim($this->name), 'driver' => $this->driver, 'role' => $this->role, 'config' => $config];
        $existing ? $existing->update($attributes) : StorageLocation::create($attributes);

        $this->editing = null;
        session()->flash('success', 'Storage location saved.');
    }

    public function delete(int $id): void
    {
        $location = StorageLocation::findOrFail($id);

        if ($location->files()->exists()) {
            session()->flash('error', "{$location->name} still has files. Delete or move them first.");

            return;
        }

        $location->delete();
        session()->flash('success', "{$location->name} removed.");
    }

    public function test(int $id): void
    {
        $location = StorageLocation::findOrFail($id);

        try {
            foreach ((new FileStore($location))->files() as $_) {
                break;
            }
        } catch (\Throwable $e) {
            session()->flash('error', "{$location->name} cannot be read: {$e->getMessage()}");

            return;
        }

        session()->flash('success', "{$location->name} is reachable.");
    }

    public function scan(int $id): void
    {
        $location = StorageLocation::findOrFail($id);

        StorageTasks::queue(StorageTasks::SCAN, $id)
            ? session()->flash('success', "Scan of {$location->name} queued.")
            : session()->flash('error', "{$location->name} is already queued or running.");
    }

    public function import(): void
    {
        $this->queueGlobal(StorageTasks::IMPORT, 'Import of the inbox queued.');
    }

    public function reorganize(): void
    {
        $this->queueGlobal(StorageTasks::REORGANIZE, 'Reorganizing the library queued.');
    }

    private function queueGlobal(string $task, string $message): void
    {
        StorageTasks::queue($task)
            ? session()->flash('success', $message)
            : session()->flash('error', 'That is already queued or running.');
    }

    public function render()
    {
        $locations = StorageLocation::withCount('files')->orderBy('name')->get();

        return view('livewire.storage-manager', [
            'locations' => $locations,
            'scanStatus' => $locations->mapWithKeys(fn ($l) => [$l->id => StorageTasks::status(StorageTasks::SCAN, $l->id)]),
            'importStatus' => StorageTasks::status(StorageTasks::IMPORT),
            'reorganizeStatus' => StorageTasks::status(StorageTasks::REORGANIZE),
            'polling' => StorageTasks::anyActive($locations->pluck('id')->all()),
            'hasInbox' => $locations->contains('role', StorageLocation::ROLE_INBOX),
        ]);
    }
}
