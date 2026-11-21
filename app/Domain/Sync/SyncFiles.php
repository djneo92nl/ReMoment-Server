<?php

namespace App\Domain\Sync;

use App\Domain\Storage\FileStore;
use App\Models\Media\Track;
use App\Models\SyncArtifact;
use App\Models\SyncTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves one file of a sync (docs/architecture/offline-sync.md section 5): a copy straight from the original, an artifact
 * from the cache. Both answer Range requests (BinaryFileResponse), so a download resumes. The request is checked against
 * the target's rules *now*: a fingerprint that is no longer the right one is `404 stale` (the executor re-plans), an
 * artifact still being made is `409 preparing`.
 */
final class SyncFiles
{
    public function __construct(private readonly SyncService $service = new SyncService, private readonly SyncPlanner $planner = new SyncPlanner) {}

    public function serve(SyncTarget $target, string $key, string $fingerprint): Response|JsonResponse|RedirectResponse
    {
        if (!preg_match('/^track:(\d+)$/', $key, $m) || !($track = Track::with(['artist', 'album'])->find((int) $m[1]))) {
            return $this->error('not_found', 'No such file.', 404);
        }

        $entry = $this->planner->entryFor($this->service->rules($target), $track);
        if ($entry === null || $entry['fingerprint'] !== $fingerprint) {
            return $this->error('stale', 'This file changed or is no longer wanted; plan again.', 404);
        }

        $file = $entry['file'];
        if ($entry['delivery'] === 'copy') {
            $store = new FileStore($file->location);
            if (!$store->exists($file->path)) {
                return $this->error('not_found', 'The file is missing from the library.', 404);
            }
            $headers = ['Content-Type' => $file->mime_type ?: 'application/octet-stream', 'Accept-Ranges' => 'bytes'] + ($entry['sha256'] ? ['X-Sync-Sha256' => $entry['sha256']] : []);
            if ($file->location->isLocal()) {
                return response()->file($store->disk()->path($file->path), $headers);
            }
            try {
                return redirect()->away($store->disk()->temporaryUrl($file->path, now()->addHours((int) config('sync.url_hours'))));
            } catch (\RuntimeException) {
                return $store->disk()->response($file->path, null, $headers);
            }
        }

        $artifact = SyncArtifact::where('fingerprint', $fingerprint)->first();
        if (!$artifact || $artifact->state !== SyncArtifact::READY || !SyncArtifacts::disk()->exists($artifact->path())) {
            if (!$artifact || $artifact->state === SyncArtifact::READY) {
                SyncArtifacts::request($fingerprint, $entry['spec']); // gone from the disk: make it again
            }

            return $artifact?->state === SyncArtifact::FAILED
                ? $this->error('failed', (string) $artifact->error, 502)
                : $this->error('preparing', 'The file is being prepared; ask again shortly.', 409);
        }

        $artifact->forceFill(['last_used_at' => now()])->save();
        $mime = $entry['spec']['target'] ? (string) config('export.formats.'.$entry['spec']['target']['codec'].'.mime', 'application/octet-stream') : 'audio/mpeg';

        return response()->file(SyncArtifacts::disk()->path($artifact->path()), [
            'Content-Type' => $mime, 'Accept-Ranges' => 'bytes', 'X-Sync-Sha256' => (string) $artifact->sha256,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
