<?php

namespace App\Domain\Sync;

use App\Domain\Export\ExportFormats;
use App\Domain\Export\ExportTranscoder;
use App\Domain\Storage\FileStore;
use App\Jobs\PrepareSyncArtifact;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\SyncArtifact;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The shared cache of prepared files (docs/architecture/offline-sync.md section 5): a transcode or remux is made once per
 * fingerprint, whatever target asks, stored under `sync-artifacts/{fingerprint}.{ext}`, and pruned least-recently-used
 * first to `SYNC_CACHE_GB`. Copies are never artifacts (they are served from the original). The encode is
 * `ExportTranscoder`, the same code as export.
 */
final class SyncArtifacts
{
    public static function disk(): Filesystem
    {
        return Storage::disk(config('sync.disk'));
    }

    public static function enabled(): bool
    {
        return (float) config('sync.cache_gb') > 0;
    }

    /**
     * The artifact row for a fingerprint, starting its preparation when there is none (or a failed one is stale).
     *
     * @param  array<string, mixed>  $spec  `track_id`, `file_id`, `extension`, `target` (codec, bitrate, sample_rate, stereo, gain_db) or null with `remux`, `estimate`
     */
    public static function request(string $fingerprint, array $spec): SyncArtifact
    {
        $artifact = SyncArtifact::where('fingerprint', $fingerprint)->first();

        if ($artifact && ($artifact->state !== SyncArtifact::FAILED || $artifact->updated_at->diffInMinutes(now()) < 10)) {
            return $artifact;
        }

        $artifact ??= SyncArtifact::create(['fingerprint' => $fingerprint, 'file_id' => $spec['file_id'], 'extension' => $spec['extension'], 'state' => SyncArtifact::PREPARING]);
        $artifact->forceFill(['state' => SyncArtifact::PREPARING, 'error' => null, 'file_id' => $spec['file_id']])->save();
        PrepareSyncArtifact::dispatch($fingerprint, $spec);

        return $artifact;
    }

    /** Makes the file (job body). A failure is stored on the row, never thrown to the queue. */
    public static function prepare(string $fingerprint, array $spec): void
    {
        $artifact = SyncArtifact::where('fingerprint', $fingerprint)->first();
        if (!$artifact || $artifact->state === SyncArtifact::READY) {
            return;
        }

        $work = sys_get_temp_dir().'/remoment-sync-'.bin2hex(random_bytes(6));
        try {
            $file = File::with('location')->find($spec['file_id']);
            $track = Track::with(['artist', 'album'])->find($spec['track_id']);
            if (!$file || $file->status !== File::PRESENT || !$track) {
                throw new \RuntimeException('file_missing');
            }
            self::guardSpace((int) ($spec['estimate'] ?? 0));
            @mkdir($work, 0775, true);
            $out = "{$work}/out.{$spec['extension']}";
            $tx = new ExportTranscoder($work);

            (new FileStore($file->location))->withLocalPath($file->path, function (string $local) use ($tx, $spec, $track, $out) {
                if (!empty($spec['remux'])) {
                    $tx->remuxId3v23($local, $out);

                    return;
                }
                $target = $spec['target'];
                $item = new \App\Domain\Export\ExportItem($track->id, null, 'transcode', 'sync', $target, 0);
                if (ExportFormats::missing($target['codec'])) {
                    throw new \RuntimeException("no encoder for {$target['codec']}");
                }
                $tx->transcode($item, $track, $local, $out);
            });

            $stream = fopen($out, 'rb');
            self::disk()->put($artifact->path(), $stream);
            is_resource($stream) && fclose($stream);

            $artifact->forceFill([
                'state' => SyncArtifact::READY, 'size' => (int) filesize($out), 'sha256' => hash_file('sha256', $out),
                'error' => null, 'last_used_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $artifact->forceFill(['state' => SyncArtifact::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        } finally {
            exec('rm -rf '.escapeshellarg($work));
        }

        self::prune();
    }

    /** Marks artifacts as used now, so the cache keeps them for a while. @param list<string> $fingerprints */
    public static function touch(array $fingerprints): void
    {
        foreach (array_chunk($fingerprints, 500) as $chunk) {
            SyncArtifact::whereIn('fingerprint', $chunk)->update(['last_used_at' => now()]);
        }
    }

    /** Refuses to start an encode that would not fit next to the cache. */
    private static function guardSpace(int $estimate): void
    {
        try {
            $free = @disk_free_space(dirname(self::disk()->path('sync-artifacts/x')) ?: sys_get_temp_dir());
        } catch (\Throwable) {
            $free = false; // a remote disk: nothing to check here
        }
        $need = $estimate + (int) config('export.free_margin_mb') * 1024 * 1024;
        if ($free !== false && $free < $need) {
            throw new \RuntimeException('Not enough free disk space for the artifact cache.');
        }
    }

    /**
     * Keeps the cache under `SYNC_CACHE_GB`: the least recently used ready artifacts go first, never one used in the last
     * `SYNC_PROTECT_MINUTES`. Also drops stale rows (stuck preparing, old failures) and files no row knows.
     *
     * @return array{removed: int, bytes: int, orphans: int, stale: int}
     */
    public static function prune(): array
    {
        $disk = self::disk();
        $result = ['removed' => 0, 'bytes' => 0, 'orphans' => 0, 'stale' => 0];
        $protect = now()->subMinutes((int) config('sync.protect_minutes'));
        $cap = (int) round((float) config('sync.cache_gb') * 1024 ** 3);

        // Rows that never became ready: stuck for longer than two encode timeouts, or failed a while ago.
        $stuck = now()->subSeconds(2 * (int) config('sync.artifact_timeout') + 600);
        foreach (SyncArtifact::where('state', '!=', SyncArtifact::READY)->where('updated_at', '<', $stuck)->get() as $row) {
            $disk->delete($row->path());
            $row->delete();
            $result['stale']++;
        }

        $ready = SyncArtifact::where('state', SyncArtifact::READY)->get()
            ->sortBy(fn (SyncArtifact $a) => ($a->last_used_at ?? $a->created_at)->getTimestamp());
        $total = (int) $ready->sum('size');

        foreach ($ready as $row) {
            if ($total <= $cap) {
                break;
            }
            if (($row->last_used_at ?? $row->created_at) >= $protect) {
                continue;
            }
            $disk->delete($row->path());
            $total -= $row->size;
            $result['removed']++;
            $result['bytes'] += $row->size;
            $row->delete();
        }

        $known = SyncArtifact::pluck('fingerprint')->flip();
        foreach ($disk->files('sync-artifacts') as $path) {
            $fingerprint = pathinfo($path, PATHINFO_FILENAME);
            if (!isset($known[$fingerprint]) && $disk->lastModified($path) < now()->subHour()->getTimestamp()) {
                $disk->delete($path);
                $result['orphans']++;
            }
        }

        return $result;
    }

    public static function size(): int
    {
        return (int) SyncArtifact::where('state', SyncArtifact::READY)->sum('size');
    }
}
