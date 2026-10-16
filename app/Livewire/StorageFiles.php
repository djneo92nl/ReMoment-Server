<?php

namespace App\Livewire;

use App\Domain\Library\Enrichment;
use App\Domain\Storage\AudioTags;
use App\Domain\Storage\FileStore;
use App\Domain\Storage\LibraryFiles;
use App\Models\File;
use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * File browser on /settings/storage: folders of a location with breadcrumbs, per-file parsed and
 * enriched state, retagging, hide from library, verify and delete. A search or a state filter lists
 * the matching files flat instead of by folder.
 */
class StorageFiles extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    /** State filters; each lists files flat. */
    public const STATUSES = [
        'missing' => 'Missing',
        'hidden' => 'Hidden',
        'not_parsed' => 'Not parsed',
        'not_enriched' => 'Not enriched',
    ];

    #[Url(as: 'location')]
    public string $locationId = '';

    #[Url]
    public string $dir = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'state')]
    public string $statusFilter = '';

    /** Id of the file whose tags are being edited. */
    public ?int $editing = null;

    /** @var array<string, string> */
    public array $tags = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'locationId', 'statusFilter'], true)) {
            $this->resetPage();
        }
        if ($property === 'locationId') {
            $this->dir = '';
        }
    }

    public function open(string $dir): void
    {
        $this->dir = trim($dir, '/');
        $this->search = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function startEdit(int $id): void
    {
        $file = File::with(['tracks.artist', 'tracks.album'])->findOrFail($id);
        $store = new FileStore($file->location);

        try {
            $read = $store->withLocalPath($file->path, fn (string $local) => AudioTags::read($local)) ?? [];
        } catch (\Throwable $e) {
            $this->toast($e->getMessage(), error: true);

            return;
        }

        $this->editing = $id;
        $this->tags = [
            'title' => (string) ($read['title'] ?? ''),
            'artist' => (string) ($read['artist'] ?? ''),
            'album' => (string) ($read['album'] ?? ''),
            'album_artist' => (string) ($read['album_artist'] ?? ''),
            'year' => (string) ($read['year'] ?? ''),
            'track_number' => (string) ($read['track_number'] ?? ''),
            'disc_number' => (string) ($read['disc_number'] ?? ''),
            'genre' => implode('; ', $read['genres'] ?? []),
        ];
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
    }

    public function saveTags(LibraryFiles $files): void
    {
        $file = File::findOrFail($this->editing);

        try {
            $files->retag($file, array_map('trim', $this->tags));
        } catch (\Throwable $e) {
            $this->toast($e->getMessage(), error: true);

            return;
        }

        $this->editing = null;
        $this->toast('Tags written, the file is matched again and enrichment is queued.');
    }

    public function rematch(int $id, LibraryFiles $files): void
    {
        $files->rematch(File::findOrFail($id));
        $this->toast('The file is matched to its track again.');
    }

    public function enrich(int $id, LibraryFiles $files): void
    {
        $queued = $files->enrich(File::findOrFail($id));
        $this->toast($queued > 0 ? "Queued {$queued} enrichment ".($queued === 1 ? 'job.' : 'jobs.') : 'Nothing left to fetch.');
    }

    public function toggleHidden(int $id, LibraryFiles $files): void
    {
        $file = File::findOrFail($id);
        $files->setHidden($file, !$file->isHidden());
        $this->toast($file->isHidden() ? 'Hidden from the library.' : 'Shown in the library again.');
    }

    public function setFolderHidden(string $dir, bool $hidden, LibraryFiles $files): void
    {
        $count = $files->setFolderHidden($this->location() ?? abort(404), $dir, $hidden);
        $this->toast($count.' '.($count === 1 ? 'file' : 'files').($hidden ? ' hidden from' : ' shown in').' the library.');
    }

    public function verify(int $id, LibraryFiles $files): void
    {
        $result = $files->verify(File::findOrFail($id));

        $this->toast(match ($result) {
            'ok' => 'The file matches its stored hash.',
            'missing' => 'The file is missing from its location.',
            default => 'The file changed on disk and was re-indexed.',
        }, error: $result === 'missing');
    }

    public function delete(int $id, LibraryFiles $files): void
    {
        try {
            $files->deleteFile(File::findOrFail($id));
        } catch (\Throwable $e) {
            $this->toast($e->getMessage(), error: true);

            return;
        }

        $this->toast('File deleted.');
    }

    private function toast(string $message, bool $error = false): void
    {
        session()->flash($error ? 'files-error' : 'files-success', $message);
    }

    private function location(): ?StorageLocation
    {
        $locations = StorageLocation::orderBy('name')->get();

        return $locations->firstWhere('id', (int) $this->locationId)
            ?? $locations->firstWhere('role', StorageLocation::ROLE_LIBRARY)
            ?? $locations->first();
    }

    private function flat(): bool
    {
        return $this->search !== '' || $this->statusFilter !== '';
    }

    public function render()
    {
        $location = $this->location();
        $folders = collect();
        $files = new LengthAwarePaginator(new EloquentCollection, 0, self::PER_PAGE);
        $parsed = [];

        if ($location) {
            [$folders, $files] = $this->flat() ? [collect(), $this->flatFiles($location)] : $this->browse($location);
            $files->getCollection()->load(['tracks.artist', 'analysis']);
            $pending = Enrichment::pendingByTrack($files->getCollection()->flatMap->tracks);
            foreach ($files as $file) {
                $track = $file->tracks->first();
                $parsed[$file->id] = [
                    'track' => $track,
                    'pending' => $track ? ($pending[$track->id] ?? []) : null,
                ];
            }
        }

        return view('livewire.storage-files', [
            'files' => $files,
            'folders' => $folders,
            'parsed' => $parsed,
            'location' => $location,
            'locations' => StorageLocation::orderBy('name')->get(['id', 'name']),
            'crumbs' => $this->crumbs(),
            'flat' => $this->flat(),
            'total' => $location ? File::where('storage_location_id', $location->id)->count() : 0,
            'missing' => $location ? File::where('storage_location_id', $location->id)->where('status', File::MISSING)->count() : 0,
            'hiddenCount' => $location ? File::where('storage_location_id', $location->id)->whereNotNull('hidden_at')->count() : 0,
        ]);
    }

    /** @return list<array{name: string, path: string}> */
    private function crumbs(): array
    {
        $crumbs = [];
        $path = '';
        foreach (array_filter(explode('/', $this->dir), fn ($part) => $part !== '') as $part) {
            $path .= ($path === '' ? '' : '/').$part;
            $crumbs[] = ['name' => $part, 'path' => $path];
        }

        return $crumbs;
    }

    private function flatFiles(StorageLocation $location): LengthAwarePaginator
    {
        return File::query()
            ->where('storage_location_id', $location->id)
            ->when($this->search !== '', fn ($q) => $q->where('path', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%'))
            ->when($this->statusFilter === 'missing', fn ($q) => $q->where('status', File::MISSING))
            ->when($this->statusFilter === 'hidden', fn ($q) => $q->whereNotNull('hidden_at'))
            ->when($this->statusFilter === 'not_parsed', fn ($q) => $q->whereDoesntHave('trackFiles'))
            ->when($this->statusFilter === 'not_enriched', fn ($q) => $q->whereHas('tracks')->whereDoesntHave('tracks.metadata', fn ($m) => $m->where('key', 'enriched_at')))
            ->orderBy('path')
            ->paginate(self::PER_PAGE);
    }

    /**
     * The folders directly under the current directory, then the files in it, as one paginated list
     * (folders first). The folder name is cut out of the path in SQL, so a big library isn't loaded.
     *
     * @return array{0: Collection<int, object>, 1: LengthAwarePaginator}
     */
    private function browse(StorageLocation $location): array
    {
        $prefix = $this->dir === '' ? '' : $this->dir.'/';
        $start = mb_strlen($prefix) + 1;
        $find = DB::connection()->getDriverName() === 'pgsql' ? 'strpos' : 'instr';
        $rest = "substr(path, {$start})";

        $under = fn () => File::query()
            ->where('storage_location_id', $location->id)
            ->when($prefix !== '', fn ($q) => $q->whereRaw('substr(path, 1, ?) = ?', [mb_strlen($prefix), $prefix]));

        $folderQuery = fn () => $under()->whereRaw("{$find}({$rest}, '/') > 0")
            ->selectRaw("substr({$rest}, 1, {$find}({$rest}, '/') - 1) as name, count(*) as files, sum(size) as bytes, "
                .'sum(case when status = ? then 1 else 0 end) as missing, sum(case when hidden_at is not null then 1 else 0 end) as hidden', [File::MISSING])
            ->groupBy('name')
            ->orderBy('name');
        $fileQuery = fn () => $under()->whereRaw("{$find}({$rest}, '/') = 0")->orderBy('path');

        $page = max(1, LengthAwarePaginator::resolveCurrentPage());
        $offset = ($page - 1) * self::PER_PAGE;

        $folderTotal = DB::query()->fromSub($folderQuery(), 'f')->count();
        $folders = $offset < $folderTotal ? $folderQuery()->offset($offset)->limit(self::PER_PAGE)->get() : collect();

        $fileOffset = max(0, $offset - $folderTotal);
        $room = self::PER_PAGE - $folders->count();
        $fileTotal = $fileQuery()->count();
        $slice = $room > 0 ? $fileQuery()->offset($fileOffset)->limit($room)->get() : new EloquentCollection;

        $paginator = new LengthAwarePaginator($slice, $folderTotal + $fileTotal, self::PER_PAGE, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);

        return [$folders, $paginator];
    }
}
