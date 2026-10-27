<?php

namespace Tests\Feature;

use App\Jobs\RunStorageTask;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** The queue runners' unattended-operation settings (docs/architecture/queue-review.md). */
class QueueStabilityConfigTest extends TestCase
{
    /** @return array<string, array{command: string, stopwaitsecs: int}> */
    private function supervisorPrograms(): array
    {
        $programs = [];
        $current = null;

        foreach (file(base_path('docker/8.4/supervisord.prod.conf'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\[program:(.+)\]$/', $line, $m)) {
                $current = $m[1];
                $programs[$current] = ['command' => '', 'stopwaitsecs' => 10];   // supervisor's default
            } elseif ($current && preg_match('/^(command|stopwaitsecs)=(.*)$/', $line, $m)) {
                $programs[$current][$m[1]] = $m[1] === 'stopwaitsecs' ? (int) $m[2] : $m[2];
            }
        }

        return $programs;
    }

    private function option(string $command, string $name): ?int
    {
        return preg_match("/--{$name}=(\d+)/", $command, $m) ? (int) $m[1] : null;
    }

    public function test_supervisor_waits_for_the_longest_job_before_killing_a_worker(): void
    {
        $programs = $this->supervisorPrograms();

        $this->assertGreaterThan((new RunStorageTask('scan'))->timeout, $programs['storage-queue']['stopwaitsecs']);
        $this->assertGreaterThan((int) config('analysis.timeout'), $programs['analysis-queue']['stopwaitsecs']);
        $this->assertGreaterThanOrEqual(900, $programs['queue']['stopwaitsecs']);   // BuildSdCardExport's timeout
    }

    public function test_every_worker_has_a_memory_limit_and_a_timeout_below_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach (['queue', 'storage-queue', 'analysis-queue'] as $name) {
            $command = $this->supervisorPrograms()[$name]['command'];

            $this->assertNotNull($this->option($command, 'memory'), "{$name} has no --memory");
            $this->assertLessThan($retryAfter, $this->option($command, 'timeout') ?? 60, "{$name} --timeout must stay below retry_after");
        }
    }

    public function test_the_storage_task_lock_expires_when_its_worker_died(): void
    {
        $this->assertGreaterThan((new RunStorageTask('scan'))->timeout, (new RunStorageTask('scan'))->uniqueFor);
    }

    public function test_failed_jobs_are_pruned_and_the_overlap_locks_of_frequent_commands_expire_quickly(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($e) => str_contains($e->command, 'queue:prune-failed')));

        $warm = $events->first(fn ($e) => str_contains($e->command, 'library:warm'));
        $this->assertNotNull($warm);
        $this->assertLessThanOrEqual(30, $warm->expiresAt);   // minutes; the default of 1440 outlives a killed run by a day
    }
}
