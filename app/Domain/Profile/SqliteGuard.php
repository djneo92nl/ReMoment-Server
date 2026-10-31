<?php

namespace App\Domain\Profile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SQLite's locking and WAL's shared-memory file do not hold across a host/VM boundary: a database on a bind mount from
 * macOS or Windows (virtiofs, osxfs, 9p ...) or on a network share corrupted twice (docs/architecture/database.md). This
 * finds the filesystem the database file lives on and says so when it is one of those. A plain Linux bind mount is the
 * host's own filesystem and cannot be told from a volume, so it is not detected: the compose file uses a named volume.
 */
final class SqliteGuard
{
    /** Filesystems where SQLite must not run, from /proc/self/mountinfo. */
    private const RISKY = ['fuse', 'fuseblk', 'fuse.grpcfuse', 'fuse.osxfs', 'fuse.sshfs', 'fuse.vmhgfs-fuse', 'virtiofs', 'grpcfuse', 'osxfs', 'fakeowner', '9p', 'nfs', 'nfs4', 'cifs', 'smb3', 'smbfs', 'vboxsf', 'afs'];

    /** The warning for the configured database, null when it is fine or not a file. */
    public static function warning(?string $mountinfo = null): ?string
    {
        $config = config('database.connections.'.config('database.default'));
        if (($config['driver'] ?? null) !== 'sqlite' || in_array($config['database'] ?? ':memory:', [':memory:', ''], true) || str_contains((string) $config['database'], 'mode=memory')) {
            return null;
        }

        $file = (string) $config['database'];
        $mountinfo ??= is_readable('/proc/self/mountinfo') ? (string) file_get_contents('/proc/self/mountinfo') : '';

        $type = self::filesystemOf(realpath(dirname($file)) ?: dirname($file), $mountinfo);
        if ($type === null || !in_array($type, self::RISKY, true)) {
            return null;
        }

        return "The SQLite database {$file} is on a '{$type}' filesystem (a bind mount from the host or a network share). "
            .'SQLite locking does not hold there and the file can corrupt: keep it on a named Docker volume or the host\'s own disk '
            .'(docs/architecture/database.md). Set DB_JOURNAL_MODE=DELETE at least to avoid WAL on it.';
    }

    /** The filesystem type of the mount that contains $path, from mountinfo text. */
    public static function filesystemOf(string $path, string $mountinfo): ?string
    {
        $best = null;
        $bestLength = -1;

        foreach (explode("\n", $mountinfo) as $line) {
            // 36 35 98:0 /mnt1 /mnt2 rw,noatime master:1 - ext3 /dev/root rw,errors=continue
            if (!preg_match('/^\S+ \S+ \S+ \S+ (\S+) .* - (\S+) /', $line, $m)) {
                continue;
            }

            $mount = str_replace('\040', ' ', $m[1]);
            $inside = $mount === '/' || $path === $mount || str_starts_with($path, rtrim($mount, '/').'/');
            if ($inside && strlen($mount) > $bestLength) {
                $best = $m[2];
                $bestLength = strlen($mount);
            }
        }

        return $best;
    }

    /** Log the warning at most once a day (any worker may be first), never failing the request. */
    public static function logOnce(): void
    {
        try {
            $warning = self::warning();
            if ($warning !== null && Cache::add('profile:sqlite-warning', true, 86400)) {
                Log::warning($warning);
            }
        } catch (\Throwable) {
            // Boot must never fail over a hint.
        }
    }

    /** Is the default connection a SQLite file (not in memory)? */
    public static function usesFile(): bool
    {
        return DB::getDriverName() === 'sqlite' && !in_array(config('database.connections.sqlite.database'), [':memory:', ''], true);
    }
}
