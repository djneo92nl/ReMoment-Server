<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupStatus;
use App\Models\Device;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The real SQLite path (VACUUM INTO of a file database), which a test wrapped in a transaction cannot take. */
class BackupFileDatabaseTest extends TestCase
{
    public function test_a_file_database_is_snapshotted_with_vacuum_into_and_restored(): void
    {
        $dir = sys_get_temp_dir().'/remoment-backup-file-'.uniqid();
        mkdir($dir);
        config([
            'database.connections.filedb' => ['driver' => 'sqlite', 'database' => $dir.'/app.sqlite', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'filedb',
            'backup.path' => $dir.'/backups',
        ]);
        DB::purge('filedb');
        touch($dir.'/app.sqlite');
        Artisan::call('migrate', ['--force' => true]);

        Device::factory()->create(['device_name' => 'Kitchen']);
        $this->assertSame(0, Artisan::call('backup:run'), Artisan::output());
        $archive = BackupStatus::archives()[0];

        DB::table('devices')->delete();
        $this->assertSame(0, Artisan::call('backup:restore', ['archive' => $archive]), Artisan::output());
        $this->assertSame(['Kitchen'], Device::pluck('device_name')->all());

        DB::disconnect('filedb');
        array_map('unlink', glob($dir.'/backups/*'));
        array_map('unlink', glob($dir.'/app.sqlite*'));
        rmdir($dir.'/backups');
        rmdir($dir);
    }
}
