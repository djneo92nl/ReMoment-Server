<?php

namespace Tests\Unit;

use App\Domain\Profile\Profile;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Supervisor cannot read the app config, so docker/8.4/profile-env.sh holds a shell copy of the `workers` table in
 * config/profile.php. This renders the effective program list per profile and keeps the copy honest.
 */
class ProfileSupervisorTest extends TestCase
{
    /** @return array<string, bool> program => started, from the script's START_* output */
    private function effective(string $profile): array
    {
        $result = Process::path(base_path())->env(['PROFILE' => $profile])->run('bash docker/8.4/profile-env.sh');
        $this->assertTrue($result->successful(), $result->errorOutput());

        $started = [];
        foreach (config('profile.workers') as $program => $row) {
            $this->assertMatchesRegularExpression('/^'.$row['env'].'=(true|false)$/m', $result->output(), "{$row['env']} missing");
            preg_match('/^'.$row['env'].'=(true|false)$/m', $result->output(), $m);
            $started[$program] = $m[1] === 'true';
        }

        return $started;
    }

    #[DataProvider('profiles')]
    public function test_the_shell_table_matches_config(string $profile): void
    {
        $this->assertSame(Profile::workers($profile), $this->effective($profile));
    }

    public static function profiles(): array
    {
        return ['control' => ['control'], 'standard' => ['standard'], 'full' => ['full']];
    }

    public function test_control_starts_only_history_and_the_always_on_programs(): void
    {
        $this->assertSame(['history-queue' => true, 'storage-queue' => false, 'heavy-queue' => false, 'analysis-queue' => false, 'stems-queue' => false], $this->effective('control'));
    }

    public function test_standard_has_no_analysis_workers_and_full_has_all_but_history(): void
    {
        $this->assertSame(['history-queue' => false, 'storage-queue' => true, 'heavy-queue' => true, 'analysis-queue' => false, 'stems-queue' => false], $this->effective('standard'));
        $this->assertSame(['history-queue' => false, 'storage-queue' => true, 'heavy-queue' => true, 'analysis-queue' => true, 'stems-queue' => true], $this->effective('full'));
    }

    public function test_an_unknown_or_empty_profile_is_standard(): void
    {
        $this->assertSame($this->effective('standard'), $this->effective('nonsense'));
    }

    #[DataProvider('configs')]
    public function test_every_gated_program_reads_its_start_variable(string $file): void
    {
        $conf = file_get_contents(base_path($file));

        foreach (config('profile.workers') as $program => $row) {
            $this->assertMatchesRegularExpression('/\[program:'.$program.'\].*?autostart=%\(ENV_'.$row['env'].'\)s/s', $conf, "{$program} in {$file}");
        }

        // Nothing outside the table is gated, and nothing in the table is hard-wired on.
        foreach (['php', 'queue', 'scheduler', 'listeners', 'radio-relay'] as $always) {
            preg_match('/\[program:'.$always.'\]([^\[]*)/s', $conf, $m);
            $this->assertNotEmpty($m[1] ?? '', "{$always} missing in {$file}");
            $this->assertDoesNotMatchRegularExpression('/autostart=(false|%\()/', $m[1], "{$always} in {$file} must always start");
        }
    }

    public static function configs(): array
    {
        return ['dev' => ['docker/8.4/supervisord.conf'], 'prod' => ['docker/8.4/supervisord.prod.conf']];
    }

    public function test_only_one_history_worker_may_run(): void
    {
        $conf = file_get_contents(base_path('docker/8.4/supervisord.prod.conf'));
        preg_match('/\[program:history-queue\](.*?)(?=\n\[program:)/s', $conf, $m);

        $this->assertStringNotContainsString('numprocs', $m[1], 'the single history writer must not be scaled');
        $this->assertStringContainsString('--queue=history', $m[1]);
    }
}
