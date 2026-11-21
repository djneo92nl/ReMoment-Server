<?php

namespace Tests\Feature;

use App\Domain\Alerts\AlertChecks;
use App\Domain\Alerts\Alerts;
use App\Domain\Health\Heartbeat;
use App\Domain\Health\QueueControl;
use App\Domain\Health\QueueInspector;
use App\Domain\Health\QueuePause;
use App\Domain\Health\QueueProgress;
use App\Domain\Health\QueueWorkers;
use App\Domain\Health\Store\QueueStores;
use App\Domain\Health\Store\RedisQueueStore;
use App\Jobs\ProcessArtwork;
use App\Jobs\QueueHeartbeat;
use App\Jobs\ScanNewReleases;
use App\Livewire\QueueManager;
use App\Models\Alert;
use App\Models\Setting;
use App\Queue\PausableDatabaseQueue;
use App\Queue\PausableRedisQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeRedisConnection;
use Tests\TestCase;

/** Per-queue health, pause/resume, cancel and run-first (docs/architecture/queue-management.md), on the database driver and an in-memory Redis. */
class QueueManagementPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        QueueStores::using(null);
        parent::tearDown();
    }

    private function database(): void
    {
        config(['queue.default' => 'database']);
    }

    /** @param array<string, mixed> $extra */
    private function row(string $queue, string $class = ProcessArtwork::class, array $extra = []): array
    {
        return array_merge([
            'queue' => $queue,
            'payload' => json_encode(['uuid' => (string) Str::uuid(), 'displayName' => $class, 'data' => ['commandName' => $class, 'command' => '']]),
            'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ], $extra);
    }

    private function row_of(string $name): array
    {
        return collect((new QueueInspector)->queues())->firstWhere('queue', $name);
    }

    private function workers(?array $counts): void
    {
        $this->app->instance(QueueWorkers::class, new class($counts) extends QueueWorkers
        {
            public function __construct(private ?array $fake) {}

            public function counts(): ?array
            {
                return $this->fake;
            }
        });
    }

    // --- health ---------------------------------------------------------------------------------

    public function test_a_queue_row_has_counts_oldest_age_failed_workers_and_progress(): void
    {
        $this->database();
        $this->workers(['default' => 2, 'health' => 2]);
        DB::table('jobs')->insert([
            $this->row('default', extra: ['created_at' => now()->subMinutes(10)->timestamp]),
            $this->row('default'),
            $this->row('default', extra: ['reserved_at' => now()->timestamp]),
            $this->row('default', extra: ['available_at' => now()->addHour()->timestamp]),
            $this->row('analysis'),
        ]);
        DB::table('failed_jobs')->insert([
            ['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHours(2)],
            ['uuid' => 'b', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(2)],
        ]);
        QueueProgress::record('default');

        $row = $this->row_of('default');

        $this->assertSame([2, 1, 1], [$row['waiting'], $row['delayed'], $row['running']]);
        $this->assertEqualsWithDelta(600, $row['oldest_age'], 5);
        $this->assertSame(1, $row['failed_24h']);
        $this->assertSame(2, $row['workers']);
        $this->assertSame('present', $row['worker']);
        $this->assertNotNull($row['last_progress']);
        $this->assertFalse($row['paused']);
        $this->assertSame(900, $row['lag_after']);
    }

    public function test_workers_are_absent_or_unknown_never_guessed(): void
    {
        $this->database();

        $this->workers(['health' => 1]);
        $this->assertSame('absent', $this->row_of('default')['worker']);

        $this->workers(null);
        $row = $this->row_of('default');
        $this->assertSame('unknown', $row['worker']);
        $this->assertNull($row['workers']);
    }

    public function test_workers_are_counted_from_the_queue_work_command_lines(): void
    {
        $workers = new QueueWorkers(fn () => [
            '/usr/bin/php /var/www/html/artisan queue:work --queue=health,default --tries=3',
            '/usr/bin/php /var/www/html/artisan queue:work --queue=health,default --tries=3',
            '/usr/bin/php /var/www/html/artisan queue:work --queue=analysis --timeout=900',
            '/usr/bin/php /var/www/html/artisan schedule:work',
        ]);

        $this->assertSame(['health' => 2, 'default' => 2, 'analysis' => 1], $workers->counts());
        $this->assertNull((new QueueWorkers(fn () => null))->counts());
    }

    public function test_lagging_needs_an_old_job_and_no_progress(): void
    {
        $this->database();
        $this->workers(null);
        $old = now()->subHour()->timestamp;
        DB::table('jobs')->insert($this->row('default', extra: ['created_at' => $old]));

        $this->assertTrue($this->row_of('default')['lagging']);   // waiting for an hour, nothing finished

        QueueProgress::record('default');   // something finished a moment ago: a backlog that drains
        $this->assertFalse($this->row_of('default')['lagging']);

        Cache::flush();
        DB::table('jobs')->insert($this->row('default', extra: ['reserved_at' => now()->subSeconds(30)->timestamp]));
        $this->assertFalse($this->row_of('default')['lagging']);   // a job runs within its allowed time

        DB::table('jobs')->where('reserved_at', '!=', null)->update(['reserved_at' => now()->subHours(3)->timestamp]);
        $this->assertTrue($this->row_of('default')['lagging']);   // that one has run too long

        QueuePause::pause('default');
        $this->assertFalse($this->row_of('default')['lagging']);   // paused on purpose
    }

    public function test_a_young_queue_does_not_lag(): void
    {
        $this->database();
        DB::table('jobs')->insert($this->row('default', extra: ['created_at' => now()->subMinutes(2)->timestamp]));

        $this->assertFalse($this->row_of('default')['lagging']);
    }

    public function test_the_job_listener_records_the_queue_that_finished(): void
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('getQueue')->andReturn('analysis');

        event(new JobProcessed('database', $job));

        $this->assertNotNull(QueueProgress::last('analysis'));
        $this->assertNull(QueueProgress::last('default'));
    }

    public function test_per_queue_health_is_not_available_for_the_sync_driver(): void
    {
        $this->assertNull((new QueueInspector)->queues());
    }

    // --- alert ------------------------------------------------------------------------------------

    public function test_a_lagging_queue_raises_a_warning_on_the_queue_stuck_alert_and_ends_with_the_lag(): void
    {
        $checks = new AlertChecks;
        $row = ['queue' => 'default', 'state' => 'busy', 'beat' => null, 'lagging' => true, 'oldest_age' => 5400];

        $checks->queues([$row]);
        $alert = Alert::first();
        $this->assertSame('queue.stuck', $alert->kind);
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('lag', $alert->data['reason']);
        $this->assertStringContainsString('default', $alert->title);

        $checks->queues([[...$row, 'lagging' => true]]);
        $this->assertSame(1, Alert::count());   // one alert per queue

        $checks->queues([[...$row, 'lagging' => false, 'state' => 'unknown']]);   // no heartbeat for the queue, lag over
        $this->assertFalse(Alerts::isActive('queue.stuck', 'default'));
    }

    public function test_a_stuck_runner_is_still_critical_and_outranks_the_lag(): void
    {
        $checks = new AlertChecks;
        $checks->queues([['queue' => 'heavy', 'state' => 'stuck', 'beat' => now()->subMinutes(9), 'lagging' => true, 'oldest_age' => 9000]]);

        $this->assertSame('critical', Alert::first()->severity);
        $this->assertSame('heartbeat', Alert::first()->data['reason']);
    }

    public function test_a_heartbeat_alert_is_not_ended_by_a_busy_queue_that_does_not_lag(): void
    {
        $checks = new AlertChecks;
        $checks->queues([['queue' => 'heavy', 'state' => 'stuck', 'beat' => null]]);
        $checks->queues([['queue' => 'heavy', 'state' => 'busy', 'beat' => null, 'lagging' => false]]);

        $this->assertTrue(Alerts::isActive('queue.stuck', 'heavy'));
    }

    public function test_pausing_a_queue_resolves_its_alert_and_a_paused_runner_is_not_stuck(): void
    {
        $this->database();
        Heartbeat::beat(Heartbeat::SCHEDULER);
        (new AlertChecks)->queues([['queue' => 'heavy', 'state' => 'stuck', 'beat' => null]]);

        QueuePause::pause('heavy');
        $this->assertSame('paused', (new QueueInspector)->runnerState('heavy', null, 0));

        (new AlertChecks)->queues([['queue' => 'heavy', 'state' => 'paused', 'beat' => null]]);
        $this->assertFalse(Alerts::isActive('queue.stuck', 'heavy'));
    }

    // --- pause ------------------------------------------------------------------------------------

    public function test_pause_and_resume_persist_and_survive_a_cache_flush(): void
    {
        QueuePause::pause('analysis');
        QueuePause::pause('analysis');

        $this->assertSame(['analysis'], QueuePause::all());
        $this->assertSame('["analysis"]', Setting::get('queues_paused'));

        Cache::flush();
        $this->assertTrue(QueuePause::isPaused('analysis'));

        QueuePause::resume('analysis');
        $this->assertFalse(QueuePause::isPaused('analysis'));
    }

    public function test_health_and_unknown_queues_cannot_be_paused(): void
    {
        foreach (['health', 'nonsense'] as $queue) {
            try {
                QueuePause::pause($queue);
                $this->fail("{$queue} was paused");
            } catch (\InvalidArgumentException) {
                $this->assertSame([], QueuePause::all());
            }
        }
    }

    public function test_a_paused_database_queue_hands_out_no_job_until_resumed(): void
    {
        $this->database();
        $this->assertInstanceOf(PausableDatabaseQueue::class, Queue::connection('database'));
        DB::table('jobs')->insert([$this->row('default'), $this->row('heavy')]);

        QueuePause::pause('default');

        $this->assertNull(Queue::connection('database')->pop('default'));
        $this->assertNotNull(Queue::connection('database')->pop('heavy'));   // others keep running
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->whereNull('reserved_at')->count());

        QueuePause::resume('default');
        $this->assertNotNull(Queue::connection('database')->pop('default'));
    }

    public function test_a_paused_redis_queue_does_not_touch_redis(): void
    {
        $redis = \Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
        $redis->shouldNotReceive('connection');
        $queue = new PausableRedisQueue($redis, 'default');
        QueuePause::pause('heavy');

        $this->assertNull($queue->pop('heavy'));
    }

    public function test_the_redis_and_database_connectors_are_the_pausable_ones(): void
    {
        config(['queue.connections.redis.driver' => 'redis']);
        $connector = new \ReflectionProperty(\Illuminate\Queue\QueueManager::class, 'connectors');
        $connectors = $connector->getValue(app('queue'));

        $this->assertInstanceOf(\App\Queue\PausableRedisConnector::class, $connectors['redis']());
        $this->assertInstanceOf(\App\Queue\PausableDatabaseConnector::class, $connectors['database']());
    }

    // --- cancel -----------------------------------------------------------------------------------

    public function test_cancel_removes_waiting_and_delayed_jobs_of_one_class_and_never_a_running_one(): void
    {
        $this->database();
        DB::table('jobs')->insert([
            $this->row('default'), $this->row('default'),
            $this->row('default', extra: ['available_at' => now()->addMinutes(5)->timestamp]),
            $this->row('default', extra: ['reserved_at' => now()->timestamp]),
            $this->row('default', 'App\\Jobs\\ScrobbleToLastfm'),
            $this->row('heavy'),
        ]);

        $result = (new QueueControl)->cancel('default', 'ProcessArtwork');

        $this->assertSame(['cancelled' => 3, 'skipped_running' => 0], $result);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->whereNotNull('reserved_at')->count());
        $this->assertSame(3, DB::table('jobs')->count());   // the running one, the other class, the heavy one
        $this->assertSame(1, DB::table('jobs')->where('queue', 'heavy')->count());
    }

    public function test_cancel_without_a_class_empties_the_queue_but_not_the_heartbeat(): void
    {
        $this->database();
        DB::table('jobs')->insert([
            $this->row('default'), $this->row('default', 'App\\Jobs\\ScrobbleToLastfm'),
            $this->row('default', QueueHeartbeat::class),
        ]);

        $this->assertSame(2, (new QueueControl)->cancel('default')['cancelled']);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_cancelled_unique_job_gives_its_lock_back(): void
    {
        $this->database();
        ScanNewReleases::dispatch();
        ScanNewReleases::dispatch();   // refused by the unique lock
        $this->assertSame(1, DB::table('jobs')->count());

        $this->assertSame(1, (new QueueControl)->cancel('catalogue', 'ScanNewReleases')['cancelled']);

        ScanNewReleases::dispatch();   // can be queued again at once
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_prioritise_is_refused_by_the_database_driver(): void
    {
        $this->database();

        $this->assertFalse((new QueueControl)->canReorder());
        $this->expectException(\LogicException::class);
        (new QueueControl)->prioritise('default', 'ProcessArtwork');
    }

    // --- redis store (in memory) --------------------------------------------------------------------

    /** @return array{0: FakeRedisConnection, 1: QueueControl} */
    private function redis(): array
    {
        $fake = new FakeRedisConnection;
        QueueStores::using(new RedisQueueStore(fn () => $fake));

        return [$fake, new QueueControl];
    }

    public function test_redis_stats_read_the_list_and_the_sorted_sets(): void
    {
        [$fake] = $this->redis();
        config(['queue.connections.redis.retry_after' => 3700]);
        $fake->rpush('queues:default', FakeRedisConnection::payload(ProcessArtwork::class, now()->subMinutes(7)->timestamp));
        $fake->rpush('queues:default', FakeRedisConnection::payload(ProcessArtwork::class));
        $fake->zadd('queues:default:delayed', now()->addMinute()->timestamp, FakeRedisConnection::payload(ProcessArtwork::class));
        $fake->zadd('queues:default:reserved', now()->timestamp + 3700 - 90, FakeRedisConnection::payload(ProcessArtwork::class));

        $stats = QueueStores::current()->stats('default');

        $this->assertSame([2, 1, 1], [$stats['waiting'], $stats['delayed'], $stats['running']]);
        $this->assertEqualsWithDelta(420, $stats['oldest_waiting']->diffInSeconds(now()), 3);
        $this->assertEqualsWithDelta(90, $stats['longest_running'], 3);
    }

    public function test_redis_cancel_removes_waiting_and_delayed_by_class(): void
    {
        [$fake, $control] = $this->redis();
        $keep = FakeRedisConnection::payload('App\\Jobs\\ScrobbleToLastfm');
        $fake->rpush('queues:default', FakeRedisConnection::payload(ProcessArtwork::class));
        $fake->rpush('queues:default', $keep);
        $fake->zadd('queues:default:delayed', now()->addMinute()->timestamp, FakeRedisConnection::payload(ProcessArtwork::class));
        $fake->zadd('queues:default:reserved', now()->timestamp, FakeRedisConnection::payload(ProcessArtwork::class));

        $result = $control->cancel('default', ProcessArtwork::class);

        $this->assertSame(2, $result['cancelled']);
        $this->assertSame([$keep], $fake->lists['queues:default']);
        $this->assertSame(1, $fake->zcard('queues:default:reserved'));   // running stays
    }

    public function test_redis_prioritise_moves_a_class_to_the_head_in_its_order(): void
    {
        [$fake, $control] = $this->redis();
        $a1 = FakeRedisConnection::payload('App\\Jobs\\A');
        $b1 = FakeRedisConnection::payload('App\\Jobs\\B');
        $a2 = FakeRedisConnection::payload('App\\Jobs\\A');
        $b2 = FakeRedisConnection::payload('App\\Jobs\\B');
        foreach ([$a1, $b1, $a2, $b2] as $payload) {
            $fake->rpush('queues:analysis', $payload);
        }

        $this->assertTrue($control->canReorder());
        $this->assertSame(2, $control->prioritise('analysis', 'B'));
        $this->assertSame([$b1, $b2, $a1, $a2], $fake->lists['queues:analysis']);
    }

    public function test_a_job_taken_while_cancelling_is_counted_as_skipped(): void
    {
        $fake = new class extends FakeRedisConnection
        {
            public function lrem(string $key, int $count, string $value): int
            {
                return 0;   // a worker popped it between the scan and the removal
            }
        };
        $fake->rpush('queues:default', FakeRedisConnection::payload(ProcessArtwork::class));
        QueueStores::using(new RedisQueueStore(fn () => $fake));

        $this->assertSame(['cancelled' => 0, 'skipped_running' => 1], (new QueueControl)->cancel('default'));
    }

    public function test_the_heartbeat_is_never_moved_or_cancelled_on_redis(): void
    {
        [$fake, $control] = $this->redis();
        $fake->rpush('queues:heavy', FakeRedisConnection::payload(QueueHeartbeat::class));

        $this->assertSame(0, $control->cancel('heavy')['cancelled']);
        $this->assertSame(0, $control->prioritise('heavy', 'QueueHeartbeat'));
    }

    // --- web ----------------------------------------------------------------------------------------

    public function test_the_queue_tab_shows_health_and_pauses_and_resumes(): void
    {
        $this->database();
        $this->workers(['heavy' => 1, 'catalogue' => 1]);

        Livewire::test(QueueManager::class)
            ->assertSee('data-test="queue-workers-heavy"', false)
            ->assertSee('1 worker')
            ->assertSee('failed 24h')
            ->assertSee('data-test="pause-heavy"', false)
            ->assertDontSee('data-test="pause-health"', false)   // the heartbeat queue
            ->call('pauseQueue', 'heavy')
            ->assertSee('data-test="resume-heavy"', false)
            ->assertSee('paused');

        $this->assertTrue(QueuePause::isPaused('heavy'));

        Livewire::test(QueueManager::class)->call('resumeQueue', 'heavy');
        $this->assertFalse(QueuePause::isPaused('heavy'));
    }

    public function test_the_queue_tab_cancels_a_class_and_says_how_many(): void
    {
        $this->database();
        DB::table('jobs')->insert([$this->row('default'), $this->row('default'), $this->row('default', QueueHeartbeat::class)]);

        Livewire::test(QueueManager::class)
            ->assertSee('data-test="cancel-default-ProcessArtwork"', false)
            ->assertDontSee('data-test="cancel-default-QueueHeartbeat"', false)
            ->assertDontSee('data-test="prioritise-default-ProcessArtwork"', false)   // database driver
            ->call('cancelJobs', 'default', ProcessArtwork::class)
            ->assertSee('Cancelled 2 waiting ProcessArtwork jobs');

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_the_queue_tab_explains_when_the_driver_cannot_reorder(): void
    {
        $this->database();

        Livewire::test(QueueManager::class)->call('prioritiseJobs', 'default', ProcessArtwork::class)->assertSee('cannot reorder');
    }

    public function test_the_queue_tab_refuses_to_pause_health(): void
    {
        $this->database();

        Livewire::test(QueueManager::class)->call('pauseQueue', 'health')->assertSee('cannot be paused');

        $this->assertFalse(QueuePause::isPaused('health'));
    }
}
