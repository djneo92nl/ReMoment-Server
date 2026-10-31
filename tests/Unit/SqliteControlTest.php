<?php

namespace Tests\Unit;

use App\Domain\Profile\SqliteGuard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** SQLite under the Control profile: WAL and busy_timeout when a file opens, and the bind-mount warning. */
class SqliteControlTest extends TestCase
{
    public function test_a_sqlite_file_opens_in_wal_mode_with_a_busy_timeout(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'remoment-wal-');
        config([
            'database.connections.control_probe' => array_merge(config('database.connections.sqlite'), ['database' => $file]),
        ]);

        try {
            $db = DB::connection('control_probe');
            $this->assertSame('wal', strtolower($db->selectOne('pragma journal_mode')->journal_mode));
            $this->assertSame(10000, (int) $db->selectOne('pragma busy_timeout')->timeout);
            $this->assertSame(1, (int) $db->selectOne('pragma synchronous')->synchronous);   // NORMAL
        } finally {
            DB::purge('control_probe');
            foreach ([$file, "{$file}-wal", "{$file}-shm"] as $f) {
                @unlink($f);
            }
        }
    }

    public function test_the_in_memory_test_database_still_works(): void
    {
        $this->assertSame(1, (int) DB::selectOne('select 1 as one')->one);
        $this->assertNull(SqliteGuard::warning());
    }

    public function test_the_guard_finds_the_filesystem_of_the_database_directory(): void
    {
        $mountinfo = <<<'MI'
        22 1 8:1 / / rw,relatime - ext4 /dev/sda1 rw
        40 22 0:35 / /data/sqlite rw,relatime - ext4 /dev/sdb1 rw
        41 22 0:36 / /var/www/html rw,relatime - virtiofs share rw
        MI;

        $this->assertSame('ext4', SqliteGuard::filesystemOf('/data/sqlite', $mountinfo));
        $this->assertSame('virtiofs', SqliteGuard::filesystemOf('/var/www/html/database', $mountinfo));
        $this->assertSame('ext4', SqliteGuard::filesystemOf('/tmp', $mountinfo));
    }

    public function test_the_guard_warns_for_a_database_on_a_host_share_only(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => '/var/www/html/database/database.sqlite']);
        $risky = "41 22 0:36 / /var/www/html rw,relatime - virtiofs share rw\n22 1 8:1 / / rw - ext4 /dev/sda1 rw";
        $fine = "40 22 0:35 / /var/www/html rw,relatime - ext4 /dev/sdb1 rw\n22 1 8:1 / / rw - ext4 /dev/sda1 rw";

        $this->assertStringContainsString('virtiofs', (string) SqliteGuard::warning($risky));
        $this->assertNull(SqliteGuard::warning($fine));
    }

    public function test_the_guard_ignores_other_drivers(): void
    {
        config(['database.default' => 'mariadb']);

        $this->assertNull(SqliteGuard::warning('41 22 0:36 / / rw - virtiofs share rw'));
    }
}
