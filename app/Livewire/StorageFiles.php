<?php

namespace App\Livewire;

use App\Domain\Storage\LibraryFiles;
use App\Models\File;
use App\Models\StorageLocation;
use Livewire\Component;
use Livewire\WithPagination;

/** File browser on /settings/storage: search, rename/move, verify and delete the stored files. */
class StorageFiles extends Component
{
    use WithPagination;

    public string $search = '';

    public string $locationFilter = '';

    public string $statusFilter = '';

    public ?int $renaming = null;

    public string $renamePath = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'locationFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function startRename(int $id): void
    {
        $this->renaming = $id;
        $this->renamePath = File::findOrFail($id)->path;
    }

    public function cancelRename(): void
    {
        $this->renaming = null;
    }

    public function saveRename(LibraryFiles $files): void
    {
        $file = File::findOrFail($this->renaming);

        try {
            $files->move($file, trim($this->renamePath));
        } catch (\Throwable $e) {
            session()->flash('files-error', $e->getMessage());

            return;
        }

        $this->renaming = null;
        session()->flash('files-success', 'File moved.');
    }

    public function verify(int $id, LibraryFiles $files): void
    {
        $result = $files->verify(File::findOrFail($id));

        session()->flash($result === 'ok' ? 'files-success' : 'files-error', match ($result) {
            'ok' => 'The file matches its stored hash.',
            'missing' => 'The file is missing from its location.',
            default => 'The file changed on disk and was re-indexed.',
        });
    }

    public function delete(int $id, LibraryFiles $files): void
    {
        try {
            $files->deleteFile(File::findOrFail($id));
        } catch (\Throwable $e) {
            session()->flash('files-error', $e->getMessage());

            return;
        }

        session()->flash('files-success', 'File deleted.');
    }

    public function render()
    {
        $files = File::query()
            ->with(['location', 'tracks:id,name'])
            ->when($this->search !== '', fn ($q) => $q->where('path', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%'))
            ->when($this->locationFilter !== '', fn ($q) => $q->where('storage_location_id', (int) $this->locationFilter))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderBy('path')
            ->paginate(20);

        return view('livewire.storage-files', [
            'files' => $files,
            'locations' => StorageLocation::orderBy('name')->get(['id', 'name']),
            'total' => File::count(),
            'missing' => File::where('status', File::MISSING)->count(),
        ]);
    }
}
