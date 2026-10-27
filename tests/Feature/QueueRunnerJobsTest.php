<?php

namespace Tests\Feature;

use App\Domain\Health\Heartbeat;
use App\Domain\Health\QueueInspector;
use App\Domain\Health\Queues;
use App\Domain\Radio\NowPlayingProviders;
use App\Jobs\EnrichAlbumLastfm;
use App\Jobs\EnrichTrackSpotify;
use App\Jobs\FetchSpotifyArtistImages;
use App\Jobs\ImportSpotifyAlbumById;
use App\Jobs\PollRadioTitle;
use App\Jobs\ProcessArtwork;
use App\Jobs\QueueHeartbeat;
use App\Jobs\ScrobbleToLastfm;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Retry and rate-limit settings of the jobs (docs/architecture/queue-review.md). */
class QueueRunnerJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_spotify_and_lastfm_jobs_go_through_their_rate_limiter_without_using_tries(): void
    {
        $jobs = [
            new EnrichTrackSpotify(new \App\Models\Media\Track),
            new ImportSpotifyAlbumById('abc'),
            new FetchSpotifyArtistImages([1]),
            new EnrichAlbumLastfm(new \App\Models\Media\Album),
        ];

        foreach ($jobs as $job) {
            $this->assertInstanceOf(RateLimited::class, $job->middleware()[0], $job::class);
            $this->assertSame(0, $job->tries, $job::class);   // a release by the limiter must not count as an attempt
        }

        $this->assertNotNull(RateLimiter::limiter('spotify'));
        $this->assertNotNull(RateLimiter::limiter('lastfm'));
    }

    public function test_a_lastfm_outage_of_a_few_minutes_does_not_use_up_the_tries(): void
    {
        $job = new ScrobbleToLastfm(new \App\Models\Play);

        $this->assertGreaterThanOrEqual(5, $job->tries);
        $this->assertGreaterThanOrEqual(300, $job->backoff()[1]);
    }

    public function test_artwork_has_a_timeout_above_the_worker_default(): void
    {
        $this->assertGreaterThan(60, (new ProcessArtwork('http://x/y.jpg'))->timeout);
    }

    public function test_a_second_poll_chain_ends_instead_of_polling_twice(): void
    {
        Queue::fake();
        $station = RadioStation::create(['name' => 'Test']);
        $providers = \Mockery::mock(NowPlayingProviders::class);
        $providers->shouldReceive('supports')->andReturn(true);
        $providers->shouldReceive('fetch')->once()->andReturn(['icy', 'Song']);
        Cache::put("radio:active:{$station->id}", true, 60);

        (new PollRadioTitle($station->id))->handle($providers);
        (new PollRadioTitle($station->id))->handle($providers);   // the duplicate: no second fetch, no second re-dispatch

        Queue::assertPushed(PollRadioTitle::class, 1);
    }

    public function test_the_per_queue_overview_is_only_for_redis(): void
    {
        config(['queue.default' => 'database']);

        $this->assertNull((new QueueInspector)->queues());
    }

    public function test_long_jobs_go_to_the_heavy_queue(): void
    {
        $this->assertSame('heavy', (new \App\Jobs\BuildSdCardExport)->queue);
        $this->assertSame('heavy', (new \App\Jobs\ProcessLibraryUpload('u', 1, 'p'))->queue);
        $this->assertSame('heavy', config('proxies.queue'));
    }

    public function test_every_heartbeat_queue_has_a_worker_and_the_beats_are_unique_per_queue(): void
    {
        $conf = file_get_contents(base_path('docker/8.4/supervisord.prod.conf'));

        foreach (Queues::heartbeat() as $queue) {
            $this->assertMatchesRegularExpression("/queue:work[^\n]*--queue={$queue}(,|\\s)/", $conf, $queue);
        }

        $this->assertSame('storage', (new QueueHeartbeat('storage'))->uniqueId());
        $this->assertSame('storage', (new QueueHeartbeat('storage'))->queue);
        $this->assertNotSame('health:queue', Queues::heartbeatKey('storage'));
        $this->assertSame('health:queue', Queues::heartbeatKey('health'));
    }

    public function test_a_heartbeat_marks_its_own_queue_and_the_runner_state_follows_it(): void
    {
        Cache::flush();
        Heartbeat::beat(Heartbeat::SCHEDULER);
        $inspector = new QueueInspector;

        $this->assertSame('stuck', $inspector->runnerState('storage', null, 0));

        (new QueueHeartbeat('storage'))->handle();
        $this->assertSame('ok', $inspector->runnerState('storage', Heartbeat::last('health:queue:storage'), 0));
        $this->assertSame('unknown', $inspector->runnerState('default', null, 0));   // no heartbeat of its own

        Cache::put(Heartbeat::SCHEDULER, now()->subHour()->toIso8601String(), 3600);
        $this->assertSame('unknown', $inspector->runnerState('storage', null, 0));   // nothing dispatches beats without the scheduler
    }
}
