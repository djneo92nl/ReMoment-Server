<?php

namespace App\Domain\Export;

use App\Models\Export;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * The `exports` SMB share (workstream 10/13, 1.1): a finished export's zip files are also put in a directory the
 * ReMoment Manager shares read-only over Samba (`EXPORT_SHARE_DIR`, a host directory mounted into the app at the same
 * path; empty = off). Each export gets a folder `{title} - {first 8 characters of its id}` with the same files as the
 * download: a hard link where the share directory is on the same disk as the private exports directory, else a copy.
 * The retention (`ExportRetention`) and cancelling an export remove the folder with the export. Only folders with that
 * name pattern are ever touched, so anything else put in the directory stays. Failing here never fails an export.
 * Unverified against a real Samba share; see docs/architecture/manager.md.
 */
class ExportShare
{
    private const SUFFIX = '/ - [0-9a-f]{8}$/';

    public static function dir(): ?string
    {
        $dir = rtrim(trim((string) config('export.share_dir')), '/');

        return $dir !== '' ? $dir : null;
    }

    public static function enabled(): bool
    {
        return self::dir() !== null;
    }

    /** Puts the finished export's files on the share. @param list<array{name: string, size: int, files: int}> $parts */
    public function publish(Export $export, string $sourceDir, array $parts): void
    {
        $dir = self::dir();
        if ($dir === null) {
            return;
        }

        try {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("{$dir} does not exist and cannot be created");
            }
            if (!is_writable($dir)) {
                throw new \RuntimeException("{$dir} is not writable");
            }
            $folder = $dir.'/'.self::folderName($export);
            $this->remove($export);
            @mkdir($folder, 0755);
            foreach ($parts as $part) {
                $from = "{$sourceDir}/{$part['name']}";
                $to = "{$folder}/{$part['name']}";
                if (!@link($from, $to) && !@copy($from, $to)) {
                    throw new \RuntimeException("cannot put {$part['name']} on the share");
                }
                @chmod($to, 0644);
            }
        } catch (\Throwable $e) {
            $this->remove($export);
            Log::warning("Export {$export->public_id}: not put on the exports share: {$e->getMessage()}");
        }
    }

    /** Removes the export's folder from the share; returns whether one was there. */
    public function remove(Export $export): bool
    {
        $dir = self::dir();
        if ($dir === null || !is_dir($dir)) {
            return false;
        }
        $suffix = self::suffix($export);
        $found = false;
        foreach (scandir($dir) ?: [] as $name) {
            if (str_ends_with($name, $suffix) && preg_match(self::SUFFIX, $name) && is_dir("{$dir}/{$name}") && !is_link("{$dir}/{$name}")) {
                File::deleteDirectory("{$dir}/{$name}");
                $found = true;
            }
        }

        return $found;
    }

    /**
     * Removes folders no export row knows (a row removed some other way), once an hour old.
     *
     * @param  list<string>  $knownIds  public ids of every export row
     */
    public function sweep(array $knownIds): int
    {
        $dir = self::dir();
        if ($dir === null || !is_dir($dir)) {
            return 0;
        }
        $known = array_map(fn (string $id) => substr($id, 0, 8), $knownIds);
        $removed = 0;
        foreach (scandir($dir) ?: [] as $name) {
            $path = "{$dir}/{$name}";
            if (!preg_match(self::SUFFIX, $name) || !is_dir($path) || is_link($path)) {
                continue;
            }
            if (!in_array(substr($name, -8), $known, true) && filemtime($path) < now()->subHour()->getTimestamp()) {
                File::deleteDirectory($path);
                $removed++;
            }
        }

        return $removed;
    }

    public static function folderName(Export $export): string
    {
        return ExportNames::clean(ExportSelection::title($export), 'Export', 80).self::suffix($export);
    }

    private static function suffix(Export $export): string
    {
        return ' - '.substr($export->public_id, 0, 8);
    }
}
