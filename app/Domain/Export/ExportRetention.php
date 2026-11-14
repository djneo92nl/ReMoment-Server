<?php

namespace App\Domain\Export;

use App\Models\Export;
use Illuminate\Support\Facades\Storage;

/**
 * Retention of exports (workstream 13.5): a finished export is kept `EXPORT_KEEP_HOURS`, then its files go and it is
 * marked expired; the whole `exports/` directory stays under `EXPORT_DIR_MAX_GB` (oldest finished first); an expired
 * or failed row is forgotten one keep period later; a directory without a row (and not just created) is removed.
 * Queued and running exports are never touched. Run by `php artisan export:prune` (hourly, with the file_storage feature).
 */
class ExportRetention
{
    /** @return array{expired: int, forgotten: int, orphans: int, trimmed: int, bytes: int} */
    public function prune(): array
    {
        $keep = max(1, (int) config('export.keep_hours'));
        $disk = Storage::disk(config('export.disk'));
        $result = ['expired' => 0, 'forgotten' => 0, 'orphans' => 0, 'trimmed' => 0, 'bytes' => 0];

        // 1. Finished exports past their time: remove the files, keep a row that says so.
        Export::query()->where('status', Export::DONE)->get()->each(function (Export $e) use ($keep, &$result) {
            $until = $e->expires_at ?? ($e->finished_at ?? $e->created_at)?->copy()->addHours($keep);
            if ($until && $until->isPast()) {
                $this->expire($e);
                $result['expired']++;
            }
        });

        // 2. Failed and expired rows are forgotten a keep period after they ended.
        Export::query()->whereIn('status', [Export::FAILED, Export::EXPIRED])->get()->each(function (Export $e) use ($keep, $disk, &$result) {
            $ended = $e->finished_at ?? $e->updated_at ?? $e->created_at;
            if ($ended && $ended->copy()->addHours($keep)->isPast()) {
                $disk->deleteDirectory($e->directory());
                $e->delete();
                $result['forgotten']++;
            }
        });

        // 3. Directories no export row knows (a cancelled running job, a removed row), once an hour old.
        $known = Export::query()->pluck('public_id')->all();
        foreach ($disk->directories('exports') as $dir) {
            $id = basename($dir);
            if (!in_array($id, $known, true) && $this->modified($dir) < now()->subHour()->getTimestamp()) {
                $disk->deleteDirectory($dir);
                $result['orphans']++;
            }
        }

        // 4. The directory cap, oldest finished export first.
        $cap = (int) round((float) config('export.dir_max_gb') * 1024 ** 3);
        if ($cap > 0) {
            $size = $this->size();
            foreach (Export::query()->where('status', Export::DONE)->orderBy('finished_at')->orderBy('id')->get() as $e) {
                if ($size <= $cap) {
                    break;
                }
                $size -= $this->expire($e);
                $result['trimmed']++;
            }
        }

        $result['bytes'] = $this->size();

        return $result;
    }

    /** Bytes the exports/ directory takes now. */
    public function size(): int
    {
        $disk = Storage::disk(config('export.disk'));
        $total = 0;
        foreach ($disk->allFiles('exports') as $file) {
            $total += $disk->size($file);
        }

        return $total;
    }

    /** Removes the files and marks the row expired (`finished_at` becomes the expiry time: the row is forgotten one keep period later); returns the bytes freed. */
    private function expire(Export $e): int
    {
        $disk = Storage::disk(config('export.disk'));
        $freed = 0;
        foreach ($disk->files($e->directory()) as $file) {
            $freed += $disk->size($file);
        }
        $disk->deleteDirectory($e->directory());
        $e->forceFill(['status' => Export::EXPIRED, 'part_files' => null, 'finished_at' => now()])->save();

        return $freed;
    }

    private function modified(string $dir): int
    {
        $disk = Storage::disk(config('export.disk'));
        $times = array_map(fn ($f) => $disk->lastModified($f), $disk->allFiles($dir));

        return $times ? max($times) : 0;
    }
}
