<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProfileStressSqliteTest extends TestCase
{
    public function test_a_short_run_reports_wal_no_locks_and_cleans_up(): void
    {
        $file = sys_get_temp_dir().'/remoment-stress-test-'.bin2hex(random_bytes(3)).'.sqlite';

        $code = Artisan::call('profile:stress-sqlite', ['--minutes' => 0.05, '--listeners' => 2, '--workers' => 1, '--web' => 1, '--file' => $file, '--json' => true]);
        $output = Artisan::output();
        $result = json_decode(substr($output, (int) strpos($output, '{')), true);

        $this->assertSame(0, $code, $output);
        $this->assertSame('wal', $result['journal_mode']);
        $this->assertSame(0, $result['database_is_locked']);
        $this->assertGreaterThan(0, $result['writes']);
        $this->assertSame('PASS', $result['verdict']);
        $this->assertFileDoesNotExist($file);
    }

    public function test_it_refuses_a_file_that_exists(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'remoment-existing-');

        try {
            $this->artisan('profile:stress-sqlite', ['--file' => $file, '--minutes' => 0.05])->assertFailed();
            $this->assertFileExists($file);
        } finally {
            @unlink($file);
        }
    }
}
