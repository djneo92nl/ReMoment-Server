<?php

namespace App\Domain\Backup;

use App\Domain\Alerts\Alerts;
use App\Models\Setting;

/**
 * The optional second copy of a backup: after `backup:run` succeeds, the archive is copied into one directory the
 * admin chose (Setting `backup_copy_path`: a mounted NAS share, a USB stick). A path only, by decision of 2026-11-20:
 * no cloud, no credentials in the app. The copy is atomic (temp name, then rename), keeps the same number of archives
 * as the source (only files `backup:run` names), and can never fail the backup itself: a problem raises the
 * `backup.copy_failed` alert and is kept in Setting `backup_copy_status`. See docs/architecture/backup-restore.md.
 */
final class BackupCopy
{
    public const SETTING = 'backup_copy_path';

    public const STATUS = 'backup_copy_status';

    public const ALERT = 'backup.copy_failed';

    public static function path(): ?string
    {
        $path = trim((string) Setting::get(self::SETTING, ''));

        return $path === '' ? null : $path;
    }

    /** Stores the path (null or '' clears it, and resolves the alert). Does not check it: see usable(). */
    public static function setPath(?string $path): void
    {
        $path = trim((string) $path);
        if ($path === '') {
            Setting::forget(self::SETTING);
            Setting::forget(self::STATUS);
            Alerts::resolve(self::ALERT);

            return;
        }
        Setting::set(self::SETTING, rtrim($path, '/') ?: '/');
    }

    /**
     * Whether archives can be written there, by writing and removing a small file. Never creates the directory:
     * an unmounted share must show up as missing, not as a folder on the local disk.
     *
     * @return array{ok: bool, message: string}
     */
    public static function usable(string $path, ?string $source = null): array
    {
        if (!str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            return ['ok' => false, 'message' => 'Use an absolute path without "..", for example /mnt/nas/backups.'];
        }
        if (self::sameDirectory($path, $source ?? (string) config('backup.path'))) {
            return ['ok' => false, 'message' => 'That is the backup directory itself; pick another place for the copy.'];
        }
        if (!is_dir($path)) {
            return ['ok' => false, 'message' => "{$path} does not exist. Is the share or drive mounted? The directory is not created for you."];
        }
        $probe = $path.'/.remoment-write-test-'.bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return ['ok' => false, 'message' => "{$path} is not writable for the server."];
        }
        @unlink($probe);

        return ['ok' => true, 'message' => "{$path} is writable."];
    }

    /**
     * Copies one archive to the chosen directory and prunes it to `$keep` archives. Returns null when no path is set.
     * Never throws; failure raises the alert, success resolves it.
     *
     * @return array{ok: bool, message: string, file?: string, removed?: list<string>}|null
     */
    public static function copy(string $archive, int $keep): ?array
    {
        $dir = self::path();
        if ($dir === null) {
            return null;
        }

        try {
            $result = self::doCopy($archive, $dir, max(1, $keep));
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        self::record($result, $dir);

        return $result;
    }

    /** @return array{ok: bool, message: string, at: string, file: ?string}|null the last copy, for the settings card and the API */
    public static function status(): ?array
    {
        $status = Setting::get(self::STATUS);
        $status = is_string($status) ? json_decode($status, true) : $status;

        return is_array($status) ? $status : null;
    }

    private static function doCopy(string $archive, string $dir, int $keep): array
    {
        $check = self::usable($dir, dirname($archive));
        if (!$check['ok']) {
            return $check;
        }
        if (!is_file($archive)) {
            return ['ok' => false, 'message' => 'The archive to copy is missing: '.basename($archive)];
        }

        $size = (int) filesize($archive);
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $size) {
            return ['ok' => false, 'message' => sprintf('Not enough free space in %s (%d MB free, %d MB needed).', $dir, $free / 1048576, ceil($size / 1048576))];
        }

        $final = $dir.'/'.basename($archive);
        $partial = $final.'.partial';
        $old = umask(0077);
        try {
            if (!@copy($archive, $partial) || (int) @filesize($partial) !== $size) {
                @unlink($partial);

                return ['ok' => false, 'message' => "Copying to {$dir} failed (disk full or connection lost?)."];
            }
            if (!@rename($partial, $final)) {
                @unlink($partial);

                return ['ok' => false, 'message' => "Could not finish the copy in {$dir}."];
            }
        } finally {
            umask($old);
        }

        $removed = [];
        foreach (array_slice(BackupStatus::archives($dir), 0, -$keep) as $oldArchive) {
            if (@unlink($oldArchive)) {
                $removed[] = basename($oldArchive);
            }
        }

        return ['ok' => true, 'message' => 'Copied to '.$dir, 'file' => $final, 'removed' => $removed];
    }

    private static function record(array $result, string $dir): void
    {
        Setting::set(self::STATUS, json_encode([
            'ok' => $result['ok'], 'message' => $result['message'], 'at' => now()->toIso8601String(), 'file' => $result['file'] ?? null,
        ]));

        if ($result['ok']) {
            Alerts::resolve(self::ALERT);
        } else {
            Alerts::raise(self::ALERT, '', 'warning', 'Backup copy failed', $result['message'], data: ['path' => $dir]);
        }
    }

    private static function sameDirectory(string $a, string $b): bool
    {
        $real = fn (string $p) => rtrim(realpath($p) ?: $p, '/');

        return $b !== '' && $real($a) === $real($b);
    }
}
