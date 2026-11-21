<?php

namespace Tests\Feature;

use App\Domain\Health\Soak\QueueSoak;
use App\Jobs\Soak\SoakNoop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The helper behind scripts/queue-soak/run.sh (the 24-hour run itself is the owner's; docs/architecture/queue-management.md). */
class QueueSoakTest extends TestCase
{
    use RefreshDatabase;

    public function test_enqueue_loads_every_queue_but_health_and_history_with_a_mixed_load(): void
    {
        config(['queue.default' => 'database']);

        $queued = QueueSoak::enqueue(100, seed: 7);

        $this->assertArrayNotHasKey('health', $queued);
        $this->assertContains('heavy', array_keys($queued));
        $this->assertSame(100 * count($queued), DB::table('jobs')->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', 'health')->count());

        $kinds = DB::table('jobs')->pluck('payload')->map(fn ($p) => unserialize(json_decode($p, true)['data']['command'])->kind)->countBy();
        $this->assertGreaterThan(40 * count($queued), $kinds['noop']);   // mostly instant
        $this->assertSame(['fail', 'memory', 'noop', 'sleep'], $kinds->keys()->sort()->values()->all());
        $this->assertSame(100 * count($queued), Cache::get('queue-soak:queued'));
    }

    public function test_the_jobs_do_what_their_kind_says_and_count_themselves(): void
    {
        (new SoakNoop('noop'))->handle();
        (new SoakNoop('sleep', 1))->handle();
        (new SoakNoop('memory', 1))->handle();

        $this->assertSame(3, Cache::get('queue-soak:done'));

        $this->expectException(\RuntimeException::class);
        (new SoakNoop('fail'))->handle();
    }

    public function test_a_sample_has_the_counts_and_the_worker_memory(): void
    {
        Cache::put('queue-soak:queued', 10);
        Cache::put('queue-soak:done', 4);

        $sample = QueueSoak::sample([['pid' => 5, 'queues' => 'heavy,catalogue', 'rss_kb' => 90000]]);

        $this->assertSame([10, 4], [$sample['queued'], $sample['done']]);
        $this->assertSame(90000, $sample['workers'][0]['rss_kb']);
        $this->assertIsArray($sample['queues']);
    }

    private function sample(string $at, int $rss, int $pid, bool $lagging = false, int $failed = 0): array
    {
        return [
            'at' => $at, 'queued' => 100, 'done' => 90,
            'queues' => ['default' => ['waiting' => 5, 'delayed' => 0, 'running' => 1, 'oldest_age' => 30, 'failed_24h' => $failed, 'state' => 'ok', 'lagging' => $lagging]],
            'workers' => [['pid' => $pid, 'queues' => 'health,default', 'rss_kb' => $rss]],
        ];
    }

    public function test_the_report_summarises_queues_memory_and_restarts(): void
    {
        $report = QueueSoak::report([
            $this->sample('2026-11-22T10:00:00+00:00', 80000, 11),
            $this->sample('2026-11-22T22:00:00+00:00', 120000, 11),
            $this->sample('2026-11-23T10:00:00+00:00', 70000, 12, failed: 4),   // restarted by --max-time or --memory
        ]);

        $this->assertStringContainsString('3 samples over 24.0 hours', $report);
        $this->assertStringContainsString('| default | 5 | 30 | 4 | 0 |', $report);
        $this->assertStringContainsString('| health,default | 2 | 78 | 117 | 68 | 1 |', $report);
        $this->assertStringContainsString('No queue lagged', $report);
    }

    public function test_the_report_names_what_went_wrong(): void
    {
        $report = QueueSoak::report([
            $this->sample('2026-11-22T10:00:00+00:00', 80000, 11),
            $this->sample('2026-11-22T11:00:00+00:00', 200000, 11, lagging: true),
        ]);

        $this->assertStringContainsString('queue default was lagging or stuck in 1 samples', $report);
        $this->assertStringContainsString('peaked at 195 MB', $report);
        $this->assertStringContainsString('No samples', QueueSoak::report([]));
    }

    public function test_the_command_enqueues_and_reports_from_a_file(): void
    {
        config(['queue.default' => 'database']);

        $this->artisan('queue:soak', ['action' => 'enqueue', '--per-queue' => 2])->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('jobs')->count());

        $file = tempnam(sys_get_temp_dir(), 'soak');
        file_put_contents($file, json_encode($this->sample('2026-11-22T10:00:00+00:00', 80000, 11))."\n");
        $this->artisan('queue:soak', ['action' => 'report', '--input' => $file])->expectsOutputToContain('# Queue soak')->assertSuccessful();
        $this->artisan('queue:soak', ['action' => 'report'])->assertFailed();
        unlink($file);
    }
}
