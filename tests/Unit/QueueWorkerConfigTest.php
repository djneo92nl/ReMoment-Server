<?php

namespace Tests\Unit;

use App\Domain\Health\Queues;
use App\Jobs\AnalyzeAudioFile;
use App\Jobs\AnalyzeTrackDeep;
use App\Jobs\BuildSdCardExport;
use App\Jobs\ExportLibrarySelection;
use App\Jobs\PollRadioTitle;
use App\Jobs\PrepareSyncArtifact;
use App\Jobs\ProcessArtwork;
use App\Jobs\ProcessLibraryUpload;
use App\Jobs\ReportRadioClick;
use App\Jobs\RestoreAfterAnnouncement;
use App\Jobs\RunScene;
use App\Jobs\RunStorageTask;
use App\Jobs\ScanForDevices;
use App\Jobs\SendAlertPush;
use App\Jobs\Soak\SoakNoop;
use App\Jobs\SyncFollower;
use App\Jobs\TranscodeProxy;
use App\Models\Export;
use App\Models\File;
use Tests\TestCase;

/**
 * Supervisor cannot read the app config, so `config/queues.php` holds a copy of what the worker programs are started
 * with. This keeps the copy honest (both conf files), checks the review rules of workstream 1b (memory, max-time,
 * max-jobs, an explicit --timeout, stop wait) and that no job with a `$timeout` outlives the worker that runs it.
 */
class QueueWorkerConfigTest extends TestCase
{
    private const CONFS = ['docker/8.4/supervisord.conf', 'docker/8.4/supervisord.prod.conf'];

    /** @return array<string, array{command: string, body: string}> program => its section, for the programs that run queue:work */
    private function programs(string $file): array
    {
        $conf = file_get_contents(base_path($file));
        preg_match_all('/\[program:([^\]]+)\](.*?)(?=\n\[program:|\z)/s', $conf, $m, PREG_SET_ORDER);

        $programs = [];
        foreach ($m as [, $name, $body]) {
            if (preg_match('/^command=.*artisan queue:work (.*)$/m', $body, $c)) {
                $programs[$name] = ['command' => $c[1], 'body' => $body];
            }
        }

        return $programs;
    }

    private static function option(string $command, string $name): ?int
    {
        return preg_match('/--'.$name.'=(\d+)/', $command, $m) ? (int) $m[1] : null;
    }

    public function test_the_worker_table_matches_the_supervisor_programs(): void
    {
        foreach (self::CONFS as $file) {
            $programs = $this->programs($file);
            $this->assertEqualsCanonicalizing(array_keys(config('queues.workers')), array_keys($programs), "programs in {$file}");

            foreach (config('queues.workers') as $name => $row) {
                $this->assertMatchesRegularExpression('/--queue='.preg_quote(implode(',', $row['queues']), '/').'(\s|$)/', $programs[$name]['command'], "{$name} queues in {$file}");
                $this->assertSame($row['timeout'], self::option($programs[$name]['command'], 'timeout'), "{$name} --timeout in {$file}");
            }
        }
    }

    public function test_the_prod_programs_state_their_limits_and_wait_for_a_stopping_job(): void
    {
        $programs = $this->programs('docker/8.4/supervisord.prod.conf');

        foreach (config('queues.workers') as $name => $row) {
            $command = $programs[$name]['command'];
            foreach (['timeout', 'memory', 'max-time'] as $option) {
                $this->assertNotNull(self::option($command, $option), "{$name} has no --{$option}");
            }
            // A worker that never exits grows with every job it leaks; `history` is the single SQLite writer with a cap of its own.
            $this->assertNotNull(self::option($command, 'max-jobs'), "{$name} has no --max-jobs");

            preg_match('/^stopwaitsecs=(\d+)/m', $programs[$name]['body'], $stop);
            $this->assertSame($row['stop_wait'], (int) ($stop[1] ?? 0), "{$name} stopwaitsecs");
            $this->assertGreaterThanOrEqual(min($row['timeout'] + 60, config('queues.max_stop_wait')), $row['stop_wait'], "{$name} is killed mid-job when it is stopped");
        }
    }

    public function test_every_queue_has_a_worker_program_and_a_threshold(): void
    {
        foreach ([Queues::HEALTH, Queues::DEFAULT, 'storage', 'heavy', 'catalogue', 'analysis', 'stems', 'history'] as $queue) {
            $this->assertNotNull(Queues::worker($queue), "{$queue} is served by no supervised worker");
            $this->assertGreaterThan(0, Queues::lagAfter($queue));
        }
        foreach (Queues::all() as $queue) {
            $this->assertNotNull(Queues::worker($queue), "{$queue} is served by no supervised worker");
        }
    }

    /** @return array<string, \Closure():object> every job class with its own timeout, built the way it is dispatched */
    private static function jobs(): array
    {
        return [
            ExportLibrarySelection::class => fn () => new ExportLibrarySelection(new Export),
            TranscodeProxy::class => fn () => new TranscodeProxy(new File, 'mp3-lo'),
            AnalyzeAudioFile::class => fn () => new AnalyzeAudioFile(new File),
            AnalyzeTrackDeep::class.':sections' => fn () => new AnalyzeTrackDeep(new File, 'sections'),
            AnalyzeTrackDeep::class.':stems' => fn () => new AnalyzeTrackDeep(new File, 'stems'),
            AnalyzeTrackDeep::class.':stem_audio' => fn () => new AnalyzeTrackDeep(new File, 'stem_audio'),
            ProcessLibraryUpload::class => fn () => new ProcessLibraryUpload('u', 1, 'x'),
            BuildSdCardExport::class => fn () => new BuildSdCardExport,
            RunScene::class => fn () => new RunScene(1),
            ScanForDevices::class => fn () => new ScanForDevices,
            RunStorageTask::class => fn () => new RunStorageTask('scan'),
            ProcessArtwork::class => fn () => new ProcessArtwork('http://x/y.jpg'),
            SoakNoop::class => fn () => new SoakNoop,
            PrepareSyncArtifact::class => fn () => new PrepareSyncArtifact('f', []),
            SyncFollower::class => fn () => new SyncFollower(1, 1, 'play'),
            SendAlertPush::class => fn () => new SendAlertPush(1, 'critical'),
            PollRadioTitle::class => fn () => new PollRadioTitle(1),
            RestoreAfterAnnouncement::class => fn () => new RestoreAfterAnnouncement(1, 'r'),
            ReportRadioClick::class => fn () => new ReportRadioClick('u'),
        ];
    }

    public function test_no_job_timeout_is_longer_than_its_worker(): void
    {
        foreach (self::jobs() as $name => $make) {
            $job = $make();
            $queue = $job->queue ?? Queues::DEFAULT;
            $worker = Queues::worker($queue);

            $this->assertNotNull($worker, "{$name} runs on {$queue}, which no worker serves");
            $this->assertLessThanOrEqual($worker['timeout'], $job->timeout, "{$name} (timeout {$job->timeout} s on {$queue}) outlives its worker's --timeout of {$worker['timeout']} s");
        }
    }

    public function test_a_job_that_outlives_retry_after_runs_on_a_single_worker(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        foreach (self::jobs() as $name => $make) {
            $job = $make();
            if ($job->timeout < $retryAfter) {
                continue;
            }

            // Redis hands a reserved job to a second worker once retry_after has passed; one process cannot take it while it is busy.
            $worker = Queues::worker($job->queue ?? Queues::DEFAULT);
            $this->assertSame(1, $worker['processes'], "{$name} runs longer than retry_after ({$retryAfter} s) on a queue with several workers");
            foreach ($this->programs('docker/8.4/supervisord.prod.conf') as $program => $p) {
                if (config("queues.workers.{$program}.queues") === $worker['queues']) {
                    $this->assertDoesNotMatchRegularExpression('/^numprocs=/m', $p['body'], "{$program} must stay a single process: {$name} outlives retry_after");
                }
            }
        }
    }

    public function test_every_job_with_a_timeout_is_in_the_list_above(): void
    {
        $known = array_map(fn ($key) => explode(':', $key)[0], array_keys(self::jobs()));

        foreach (glob(base_path('app/Jobs/*.php')) as $file) {
            if (preg_match('/public int \$timeout\b/', file_get_contents($file))) {
                $class = 'App\\Jobs\\'.basename($file, '.php');
                $this->assertContains($class, $known, "{$class} sets a timeout: add it to QueueWorkerConfigTest::jobs() so it is checked against its worker");
            }
        }
    }

    public function test_the_redis_retry_after_covers_every_job_that_can_share_a_worker(): void
    {
        // The longest job of any queue with more than one process (default workers) must fit retry_after.
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $this->assertGreaterThan(config('queues.workers.queue.timeout'), $retryAfter);
        $this->assertGreaterThan(config('queues.workers.storage-queue.timeout'), $retryAfter);
    }
}
