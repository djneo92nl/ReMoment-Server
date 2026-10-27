<?php

namespace Tests\Feature;

use App\Domain\Health\Heartbeat;
use App\Domain\Health\QueueWatchdog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class QueueWatchdogTest extends TestCase
{
    private array $killed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->killed = [];
    }

    private function watchdog(array $pids = [4242], ?int $running = null): QueueWatchdog
    {
        return new QueueWatchdog(fn () => $pids, function (int $pid) {
            $this->killed[] = $pid;
        }, fn () => $running);
    }

    private function beat(string $key, int $minutesAgo): void
    {
        Cache::put($key, now()->subMinutes($minutesAgo)->toIso8601String(), 3600);
    }

    public function test_a_fresh_queue_heartbeat_is_left_alone(): void
    {
        $this->beat(Heartbeat::SCHEDULER, 0);
        $this->beat(Heartbeat::QUEUE, 1);

        $this->assertSame('ok', $this->watchdog()->run());
        $this->assertSame([], $this->killed);
    }

    public function test_a_stale_queue_heartbeat_kills_the_worker(): void
    {
        Log::spy();
        $this->beat(Heartbeat::SCHEDULER, 0);
        $this->beat(Heartbeat::QUEUE, 10);

        $this->assertSame('restarted', $this->watchdog()->run());
        $this->assertSame([4242], $this->killed);
    }

    public function test_a_queue_that_never_beat_counts_as_stale(): void
    {
        Log::spy();
        $this->beat(Heartbeat::SCHEDULER, 0);

        $this->assertSame('restarted', $this->watchdog()->run());
    }

    public function test_nothing_happens_while_the_scheduler_is_down(): void
    {
        $this->beat(Heartbeat::SCHEDULER, 30);
        $this->beat(Heartbeat::QUEUE, 30);

        $this->assertSame('scheduler-down', $this->watchdog()->run());
        $this->assertSame([], $this->killed);
    }

    public function test_it_waits_after_a_restart_before_killing_again(): void
    {
        Log::spy();
        $this->beat(Heartbeat::SCHEDULER, 0);
        $this->beat(Heartbeat::QUEUE, 10);

        $this->watchdog()->run();
        $this->assertSame('cooldown', $this->watchdog()->run());
        $this->assertSame([4242], $this->killed);
    }

    public function test_a_missing_worker_is_left_to_supervisor(): void
    {
        $this->beat(Heartbeat::SCHEDULER, 0);
        $this->beat(Heartbeat::QUEUE, 10);

        $this->assertSame('no-worker', $this->watchdog([])->run());
    }

    public function test_workers_busy_with_a_recent_long_job_are_left_alone_until_it_outlasts_every_timeout(): void
    {
        Log::spy();
        $this->beat(Heartbeat::SCHEDULER, 0);
        $this->beat(Heartbeat::QUEUE, 10);

        $this->assertSame('busy', $this->watchdog(running: 600)->run());
        $this->assertSame([], $this->killed);

        $this->assertSame('restarted', $this->watchdog(running: QueueWatchdog::BUSY_GRACE_SECONDS + 1)->run());
        $this->assertSame([4242], $this->killed);
    }
}
