<?php

namespace App\Http\Controllers\Api;

use App\Domain\Storage\FileStore;
use App\Domain\Storage\LibraryFiles;
use App\Domain\Storage\StorageTasks;
use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Services\Local\LocalLibraryScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin (`admin.token`) API for storage locations and the files in them; see docs/architecture/file-storage.md. */
class StorageController extends Controller
{
    public function __construct(private LibraryFiles $files) {}

    public function locations(): JsonResponse
    {
        return response()->json(['data' => StorageLocation::withCount('files')->orderBy('name')->get()->map(fn ($l) => $this->location($l))]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $location = StorageLocation::create($this->validated($request));

        return response()->json($this->location($location), 201);
    }

    public function updateLocation(Request $request, StorageLocation $location): JsonResponse
    {
        $location->update($this->validated($request, $location));

        return response()->json($this->location($location));
    }

    public function destroyLocation(StorageLocation $location): JsonResponse
    {
        if ($location->files()->exists()) {
            return response()->json(['error' => 'not_empty', 'message' => 'This location still has files. Delete or move them first.'], 422);
        }

        $location->delete();

        return response()->json(['status' => 'ok']);
    }

    /** Checks the location can be listed (credentials, root path). */
    public function testLocation(StorageLocation $location): JsonResponse
    {
        try {
            $files = 0;
            foreach ((new FileStore($location))->files() as $_) {
                if (++$files >= 1) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'unreachable', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['status' => 'ok']);
    }

    /** With `queue=1` the scan runs on the queue (202, or 409 when one is already queued or running). */
    public function scan(Request $request, StorageLocation $location, LocalLibraryScanner $scanner): JsonResponse
    {
        if (!LocalLibraryScanner::scannable($location)) {
            return response()->json(['error' => 'inbox', 'message' => 'The inbox is not scanned; import it instead.'], 422);
        }

        if ($request->boolean('queue')) {
            return StorageTasks::queue(StorageTasks::SCAN, $location->id)
                ? response()->json(['status' => 'queued'], 202)
                : response()->json(['error' => 'busy', 'message' => 'A scan of this location is already queued or running.'], 409);
        }

        try {
            $changed = $scanner->scan($location);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'scan_failed', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['status' => 'ok', 'changed' => $changed, 'counts' => $scanner->counts()]);
    }

    public function import(Request $request): JsonResponse
    {
        return $this->guard(fn () => ['status' => 'ok', 'imported' => $this->files->importInbox($request->boolean('keep'))]);
    }

    public function reorganize(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $changes = $this->files->reorganize($request->boolean('dry_run'));

            return ['status' => 'ok', 'dry_run' => $request->boolean('dry_run'),
                'moves' => array_map(fn ($c) => ['file_id' => $c[0]->id, 'from' => $c[0]->path, 'to' => $c[1]], $changes)];
        });
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        return $this->guard(function () use ($request) {
            $upload = $request->file('file');
            $stream = fopen($upload->getRealPath(), 'rb');
            try {
                $file = $this->files->upload($upload->getClientOriginalName(), $stream);
            } finally {
                fclose($stream);
            }

            return ['file' => $this->file($file), 'track_id' => $file->tracks()->value('tracks.id')];
        }, 201);
    }

    public function trackFiles(Track $track): JsonResponse
    {
        $links = $track->trackFiles()->with('file.location')->get();

        return response()->json(['data' => $links->map(fn ($l) => $this->file($l->file) + ['role' => $l->role, 'profile' => $l->profile, 'is_primary' => $l->is_primary])]);
    }

    public function destroyFile(File $file): JsonResponse
    {
        return $this->guard(function () use ($file) {
            $this->files->deleteFile($file);

            return ['status' => 'ok'];
        });
    }

    public function destroyTrack(Track $track): JsonResponse
    {
        return $this->guard(function () use ($track) {
            $this->files->deleteTrack($track);

            return ['status' => 'ok'];
        });
    }

    public function move(Request $request, File $file): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:1000'], 'storage_location_id' => ['nullable', 'exists:storage_locations,id']]);

        return $this->guard(fn () => $this->file($this->files->move(
            $file, $data['path'], isset($data['storage_location_id']) ? StorageLocation::find($data['storage_location_id']) : null,
        )));
    }

    public function tags(Request $request, File $file): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string'], 'artist' => ['nullable', 'string'], 'album' => ['nullable', 'string'],
            'album_artist' => ['nullable', 'string'], 'year' => ['nullable', 'string', 'max:10'],
            'track_number' => ['nullable', 'integer', 'min:0'], 'disc_number' => ['nullable', 'integer', 'min:0'], 'genre' => ['nullable', 'string'],
        ]);

        return $this->guard(fn () => $this->file($this->files->writeTags($file, array_filter($data, fn ($v) => $v !== null))));
    }

    public function verify(File $file): JsonResponse
    {
        return response()->json(['result' => $this->files->verify($file)]);
    }

    /** Runs $action; an invalid request is 422, a failing disk or tag tool 502. */
    private function guard(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json($action(), $status);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'storage_error', 'message' => $e->getMessage()], 502);
        }
    }

    private function validated(Request $request, ?StorageLocation $existing = null): array
    {
        $data = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('storage_locations', 'name')->ignore($existing?->id)],
            'driver' => [$existing ? 'sometimes' : 'required', Rule::in(StorageLocation::DRIVERS)],
            'role' => ['sometimes', Rule::in([StorageLocation::ROLE_LIBRARY, StorageLocation::ROLE_INBOX])],
            'config' => [$existing ? 'sometimes' : 'required', 'array'],
            'config.root' => ['required_if:driver,local', 'string'],
            'config.bucket' => ['required_if:driver,s3', 'string'],
            'config.key' => ['nullable', 'string'], 'config.secret' => ['nullable', 'string'], 'config.region' => ['nullable', 'string'],
            'config.endpoint' => ['nullable', 'string'], 'config.prefix' => ['nullable', 'string'], 'config.use_path_style_endpoint' => ['nullable', 'boolean'],
        ]);

        return $data;
    }

    /** Secrets never leave the server: only the non-secret config is shown. */
    private function location(StorageLocation $location): array
    {
        $config = $location->config ?? [];

        return [
            'id' => $location->id, 'name' => $location->name, 'driver' => $location->driver, 'role' => $location->role,
            'config' => array_diff_key($config, array_flip(['key', 'secret'])),
            'files_count' => $location->files_count ?? $location->files()->count(),
            'last_scanned_at' => $location->last_scanned_at?->toIso8601String(),
        ];
    }

    private function file(File $file): array
    {
        return [
            'id' => $file->id, 'storage_location_id' => $file->storage_location_id, 'path' => $file->path, 'hash' => $file->hash,
            'size' => $file->size, 'mime_type' => $file->mime_type, 'codec' => $file->codec, 'bitrate' => $file->bitrate,
            'sample_rate' => $file->sample_rate, 'bit_depth' => $file->bit_depth, 'duration' => $file->duration, 'status' => $file->status,
        ];
    }
}
