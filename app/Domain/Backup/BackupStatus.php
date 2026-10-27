<?php

namespace App\Domain\Backup;

use Illuminate\Support\Carbon;

/** What is in the backup directory: the archives backup:run made (by name), newest last. */
final class BackupStatus
{
    public const PATTERN = '/^remoment-backup-(\d{8})-(\d{6})\.zip$/';

    /** @return list<string> full paths, oldest first */
    public static function archives(?string $dir = null): array
    {
        $dir ??= (string) config('backup.path');
        $found = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if (preg_match(self::PATTERN, $name) && is_file($dir.'/'.$name)) {
                $found[] = $dir.'/'.$name;
            }
        }
        sort($found);

        return $found;
    }

    public static function lastBackupAt(?string $dir = null): ?Carbon
    {
        $all = self::archives($dir);
        if (!$all || !preg_match(self::PATTERN, basename(end($all)), $m)) {
            return null;
        }

        return Carbon::createFromFormat('YmdHis', $m[1].$m[2], config('app.timezone'));
    }

    /** ['ok' => bool, 'last' => ?Carbon]: a missing backup, or one older than backup.stale_hours, is not ok. */
    public static function health(): array
    {
        $last = self::lastBackupAt();

        return [
            'ok' => $last !== null && $last->diffInHours(now(), true) < (int) config('backup.stale_hours'),
            'last' => $last,
        ];
    }
}
