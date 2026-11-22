<?php

namespace Tests\Feature\Podcasts;

use App\Domain\Device\State;
use App\Domain\Podcasts\PodcastRefresher;
use App\Domain\Resume\PlaybackPositions;
use App\Jobs\RefreshPodcast;
use App\Jobs\ResumePlayback;
use App\Models\Device;
use App\Models\PlaybackPosition;
use App\Models\Podcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSeekingStreamDriver;
use Tests\Support\FakeStreamDriver;
use Tests\Support\MakesPodcastFeeds;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** Podcasts (8.2): subscriptions, refresh (conditional GET, back-off, retention), episodes, playing and resume. No network. */
class PodcastsApiTest extends TestCase
{
    use MakesPodcastFeeds;
    use RefreshDatabase;
    use SignsInAsAdmin;

    private const FEED = 'https://show.test/feed.xml';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake(); // the cover goes to the artwork queue, which would fetch the image
        FakeStreamDriver::$played = [];
        FakeSeekingStreamDriver::$seeks = [];
    }

    private function admin(): static
    {
        return $this->withHeaders($this->adminHeaders());
    }

    private function subscribed(int $episodes = 3, array $channel = []): Podcast
    {
        $this->fakeFeed(self::FEED, $this->feedXml('The Show', array_map(fn ($n) => ['title' => "Episode {$n}"], range(1, $episodes)), $channel), ['ETag' => '"v1"', 'Last-Modified' => 'Sun, 01 Nov 2026 10:00:00 GMT']);
        $id = $this->admin()->postJson('/api/admin/podcasts', ['feed_url' => self::FEED])->assertCreated()->json('data.id');

        return Podcast::findOrFail($id);
    }

    public function test_subscribing_needs_the_admin_token_and_fetches_the_feed_at_once(): void
    {
        $this->fakeFeed(self::FEED, $this->feedXml('The Show', [['title' => 'One'], ['title' => 'Two']]));

        $this->postJson('/api/admin/podcasts', ['feed_url' => self::FEED])->assertStatus(401);

        $this->admin()->postJson('/api/admin/podcasts', ['feed_url' => self::FEED])->assertCreated()
            ->assertJsonPath('data.title', 'The Show')->assertJsonPath('data.author', 'Jane Host')
            ->assertJsonPath('data.episode_count', 2)->assertJsonPath('data.unplayed_count', 2);

        $this->admin()->postJson('/api/admin/podcasts', ['feed_url' => self::FEED])->assertStatus(409)->assertJsonPath('error', 'already_subscribed');
        $this->assertDatabaseCount('podcasts', 1);
    }

    public function test_a_bad_address_or_a_page_that_is_not_a_feed_is_refused_and_stores_nothing(): void
    {
        $this->fakeFeed('https://page.test/feed', '<html></html>');
        $this->fakeFeed('https://down.test/feed', 'no', status: 500);

        foreach (['javascript:alert(1)', 'ftp://x.test/feed', 'not a url', 'https://page.test/feed', 'https://down.test/feed'] as $url) {
            $this->admin()->postJson('/api/admin/podcasts', ['feed_url' => $url])->assertStatus(422)->assertJsonPath('error', 'invalid_feed');
        }
        $this->assertDatabaseCount('podcasts', 0);
    }

    public function test_the_list_and_the_episodes_are_open_and_paged_newest_first(): void
    {
        $podcast = $this->subscribed(5);

        $this->getJson('/api/podcasts')->assertOk()->assertJsonPath('data.0.title', 'The Show')->assertJsonPath('data.0.episode_count', 5);
        $this->getJson("/api/podcasts/{$podcast->id}")->assertOk()->assertJsonPath('data.feed_url', self::FEED);

        $first = $this->getJson("/api/podcasts/{$podcast->id}/episodes?limit=2")->assertOk();
        $this->assertSame(['Episode 5', 'Episode 4'], array_column($first->json('data'), 'title'));
        $this->assertNotNull($first->json('next_cursor'));

        $second = $this->getJson("/api/podcasts/{$podcast->id}/episodes?limit=2&cursor=".$first->json('next_cursor'))->assertOk();
        $this->assertSame(['Episode 3', 'Episode 2'], array_column($second->json('data'), 'title'));

        $last = $this->getJson("/api/podcasts/{$podcast->id}/episodes?limit=2&cursor=".$second->json('next_cursor'))->assertOk();
        $this->assertSame(['Episode 1'], array_column($last->json('data'), 'title'));
        $this->assertNull($last->json('next_cursor'));

        $this->getJson("/api/podcasts/{$podcast->id}/episodes?cursor=garbage")->assertStatus(422);
        $this->getJson('/api/podcasts/999')->assertNotFound();
    }

    public function test_marking_played_and_the_new_episodes_list(): void
    {
        $podcast = $this->subscribed(3);
        $one = $podcast->episodes()->orderBy('published_at')->first();

        $this->putJson("/api/podcast-episodes/{$one->id}/played")->assertOk()->assertJson(['played' => true]);
        $this->getJson('/api/podcast-episodes?unplayed=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/podcasts')->assertJsonPath('data.0.unplayed_count', 2);
        $this->getJson("/api/podcast-episodes/{$one->id}")->assertOk()->assertJsonPath('data.played', true);

        $this->putJson("/api/podcast-episodes/{$one->id}/played", ['played' => false])->assertOk();
        $this->assertNull($one->fresh()->played_at);

        $this->postJson("/api/podcasts/{$podcast->id}/mark-played")->assertOk()->assertJson(['changed' => 3]);
        $this->getJson('/api/podcast-episodes?unplayed=1')->assertJsonCount(0, 'data');
    }

    public function test_a_refresh_is_a_conditional_get_and_a_304_changes_nothing(): void
    {
        $podcast = $this->subscribed(2);
        $this->assertSame('"v1"', $podcast->etag);

        $this->fakeFeed(self::FEED, '', status: 304);
        $this->travel(3)->hours();

        $this->assertSame('unchanged', PodcastRefresher::refresh($podcast->fresh()));
        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"v1"') && $request->hasHeader('If-Modified-Since', 'Sun, 01 Nov 2026 10:00:00 GMT'));
        $this->assertSame(2, $podcast->episodes()->count());
    }

    public function test_a_refresh_adds_new_episodes_and_keeps_played_state(): void
    {
        $podcast = $this->subscribed(2);
        $played = $podcast->episodes()->orderBy('published_at')->first();
        $played->update(['played_at' => now()]);

        $this->fakeFeed(self::FEED, $this->feedXml('The Show (renamed)', [['title' => 'Episode 1 edited', 'guid' => 'guid-1'], ['title' => 'Episode 2'], ['title' => 'Episode 3', 'guid' => 'guid-3']]));
        $this->travel(3)->hours();

        $this->assertSame('updated', PodcastRefresher::refresh($podcast->fresh()));

        $this->assertSame('The Show (renamed)', $podcast->fresh()->title);
        $this->assertSame(3, $podcast->episodes()->count());
        $this->assertSame('Episode 1 edited', $played->fresh()->title);
        $this->assertNotNull($played->fresh()->played_at);
    }

    public function test_a_feed_is_not_asked_again_before_it_is_due_and_a_failure_backs_off(): void
    {
        $podcast = $this->subscribed(1);
        $this->fakeFeed(self::FEED, 'boom', status: 500);

        $this->assertSame('skipped', PodcastRefresher::refresh($podcast->fresh()));
        Http::assertSentCount(1); // the subscribing fetch only

        $this->assertSame('failed', PodcastRefresher::refresh($podcast->fresh(), force: true));
        $first = $podcast->fresh();
        $this->assertSame(1, $first->failures);
        $this->assertSame('The feed answered HTTP 500.', $first->last_error);
        $this->assertSame(1, $first->episodes()->count(), 'a failing feed keeps what it had');

        $firstDue = $first->next_check_at->timestamp;
        PodcastRefresher::refresh($first, force: true);
        $second = $podcast->fresh();
        $this->assertSame(2, $second->failures);
        $this->assertGreaterThan($firstDue, $second->next_check_at->timestamp);
        $this->getJson('/api/podcasts')->assertJsonPath('data.0.last_error', 'The feed answered HTTP 500.');

        $this->fakeFeed(self::FEED, $this->feedXml('The Show', [['title' => 'Back']]));
        PodcastRefresher::refresh($second, force: true);
        $this->assertSame(0, $podcast->fresh()->failures);
        $this->assertNull($podcast->fresh()->last_error);
    }

    public function test_retry_after_is_honoured(): void
    {
        $podcast = $this->subscribed(1);
        $this->fakeFeed(self::FEED, 'slow down', ['Retry-After' => '7200'], 429);

        PodcastRefresher::refresh($podcast->fresh(), force: true);

        $this->assertEqualsWithDelta(7200, now()->diffInSeconds($podcast->fresh()->next_check_at, false), 5);
    }

    public function test_the_command_queues_only_due_feeds_spaced_apart(): void
    {
        Queue::fake();
        config(['podcasts.spacing_seconds' => 4]);
        $due = $this->subscribed(1);
        $other = Podcast::create(['feed_hash' => sha1('b'), 'feed_url' => 'https://b.test/f', 'title' => 'B', 'next_check_at' => now()->subMinute()]);
        $notDue = Podcast::create(['feed_hash' => sha1('c'), 'feed_url' => 'https://c.test/f', 'title' => 'C', 'next_check_at' => now()->addHour()]);
        $due->update(['next_check_at' => now()->subMinute()]);

        $this->artisan('podcasts:refresh')->assertSuccessful();

        Queue::assertPushed(RefreshPodcast::class, 2);
        Queue::assertNotPushed(RefreshPodcast::class, fn ($job) => $job->podcastId === $notDue->id);

        Queue::fake();
        $this->artisan('podcasts:refresh --force')->assertSuccessful();
        Queue::assertPushed(RefreshPodcast::class, 3);
    }

    public function test_the_refresh_job_fetches_the_feed(): void
    {
        $podcast = $this->subscribed(1);
        $this->fakeFeed(self::FEED, $this->feedXml('The Show', [['title' => 'A'], ['title' => 'B']]));

        (new RefreshPodcast($podcast->id, force: true))->handle();

        $this->assertSame(2, $podcast->episodes()->count());
    }

    public function test_manual_refresh_is_rate_limited(): void
    {
        $podcast = $this->subscribed(1);

        $this->flushHeaders()->postJson("/api/admin/podcasts/{$podcast->id}/refresh")->assertStatus(401);
        $this->admin()->postJson("/api/admin/podcasts/{$podcast->id}/refresh")->assertStatus(429)->assertJsonPath('error', 'too_soon');

        $this->travel(10)->minutes();
        $this->fakeFeed(self::FEED, $this->feedXml('The Show', [['title' => 'A'], ['title' => 'B']]));
        $this->admin()->postJson("/api/admin/podcasts/{$podcast->id}/refresh")->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('data.episode_count', 2);
    }

    public function test_retention_keeps_the_newest_and_never_an_episode_with_a_bookmark(): void
    {
        $podcast = $this->subscribed(5);
        $oldest = $podcast->episodes()->orderBy('published_at')->first();
        PlaybackPosition::create(['kind' => 'episode', 'item_id' => $oldest->id, 'position' => 600]);

        $this->admin()->putJson("/api/admin/podcasts/{$podcast->id}", ['keep_episodes' => 2])->assertOk()->assertJsonPath('data.keep_episodes', 2);

        $titles = $podcast->episodes()->orderByDesc('published_at')->pluck('title')->all();
        $this->assertSame(['Episode 5', 'Episode 4', 'Episode 1'], $titles);

        // The feed still lists everything: what retention would prune is not stored again.
        $this->fakeFeed(self::FEED, $this->feedXml('The Show', array_map(fn ($n) => ['title' => "Episode {$n}"], range(1, 6))));
        PodcastRefresher::refresh($podcast->fresh(), force: true);
        $this->assertSame(['Episode 6', 'Episode 5', 'Episode 1'], $podcast->episodes()->orderByDesc('published_at')->pluck('title')->all());

        $this->admin()->putJson("/api/admin/podcasts/{$podcast->id}", ['keep_episodes' => 0])->assertOk()->assertJsonPath('data.keep_episodes', 0);
        PodcastRefresher::refresh($podcast->fresh(), force: true);
        $this->assertSame(6, $podcast->episodes()->count(), '0 keeps everything');
    }

    public function test_the_server_default_retention_applies_without_an_own_setting(): void
    {
        config(['podcasts.keep_episodes' => 3]);

        $podcast = $this->subscribed(6);

        $this->assertSame(3, $podcast->episodes()->count());
        $this->assertSame('Episode 6', $podcast->episodes()->orderByDesc('published_at')->value('title'));
    }

    public function test_unsubscribing_removes_episodes_and_their_bookmarks(): void
    {
        $podcast = $this->subscribed(2);
        $episode = $podcast->episodes()->first();
        PlaybackPositions::set('episode', $episode->id, 300, 3600);

        $this->flushHeaders()->deleteJson("/api/admin/podcasts/{$podcast->id}")->assertStatus(401);
        $this->admin()->deleteJson("/api/admin/podcasts/{$podcast->id}")->assertOk();

        $this->assertDatabaseCount('podcasts', 0);
        $this->assertDatabaseCount('podcast_episodes', 0);
        $this->assertDatabaseCount('playback_positions', 0);
    }

    public function test_an_episode_plays_through_the_stream_url_path(): void
    {
        $episode = $this->subscribed(1)->episodes()->first();
        $device = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$device->id}/podcast-episodes/{$episode->id}/play")
            ->assertOk()->assertJson(['status' => 'ok', 'episode_id' => $episode->id, 'title' => 'Episode 1', 'resume_position' => null]);

        $this->assertSame('https://cdn.test/1.mp3', FakeStreamDriver::$played[0][0]);
        $this->assertSame('Episode 1', FakeStreamDriver::$played[0][1]);
    }

    public function test_a_device_without_stream_url_and_an_unreachable_one_are_refused(): void
    {
        $episode = $this->subscribed(1)->episodes()->first();
        $plain = Device::factory()->inState(State::Standby)->create();
        $down = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Unreachable)->create();

        $this->postJson("/api/devices/{$plain->id}/podcast-episodes/{$episode->id}/play")->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->postJson("/api/devices/{$down->id}/podcast-episodes/{$episode->id}/play")->assertStatus(503)->assertJsonPath('error', 'unreachable');
        $this->postJson("/api/devices/{$plain->id}/podcast-episodes/9999/play")->assertNotFound();
    }

    public function test_playing_a_bookmarked_episode_resumes_and_resume_false_starts_over(): void
    {
        Queue::fake();
        $episode = $this->subscribed(1)->episodes()->first();
        PlaybackPositions::set('episode', $episode->id, 1200, 3600);
        $device = Device::factory()->driver(FakeSeekingStreamDriver::class)->inState(State::Standby)->create();

        $this->postJson("/api/devices/{$device->id}/podcast-episodes/{$episode->id}/play")->assertOk()->assertJsonPath('resume_position', 1200);
        Queue::assertPushed(ResumePlayback::class, fn ($job) => $job->kind === 'episode' && $job->itemId === $episode->id && $job->position === 1200);

        Queue::fake();
        \App\Domain\Resume\ResumeOnPlay::done($device->id);
        $this->postJson("/api/devices/{$device->id}/podcast-episodes/{$episode->id}/play", ['resume' => false])->assertOk()->assertJsonPath('resume_position', null);
        Queue::assertNothingPushed();
    }

    public function test_the_bookmark_of_an_episode_is_listed_set_and_finishing_marks_it_played(): void
    {
        $episode = $this->subscribed(1)->episodes()->first();

        $this->putJson("/api/library/positions/episode/{$episode->id}", ['position' => 900])->assertOk()->assertJson(['kind' => 'episode', 'position' => 900, 'cleared' => false]);

        $this->getJson('/api/library/positions')->assertOk()
            ->assertJsonPath('data.0.kind', 'episode')->assertJsonPath('data.0.title', 'Episode 1')->assertJsonPath('data.0.subtitle', 'The Show')
            ->assertJsonPath('data.0.duration', 3600);
        $this->getJson("/api/podcast-episodes/{$episode->id}")->assertJsonPath('data.position', 900)->assertJsonPath('data.played', false);

        // Within the tail of the episode: finished, the bookmark goes and the episode counts as played.
        $this->putJson("/api/library/positions/episode/{$episode->id}", ['position' => 3590])->assertOk()->assertJson(['cleared' => true]);
        $this->assertDatabaseCount('playback_positions', 0);
        $this->assertNotNull($episode->fresh()->played_at);
        $this->putJson('/api/library/positions/episode/9999', ['position' => 5])->assertNotFound();
    }

    public function test_the_progress_a_device_reports_keeps_the_episode_bookmark(): void
    {
        $episode = $this->subscribed(1)->episodes()->first();
        $device = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Playing)->create();

        \App\Domain\Resume\ResumeOnPlay::remember($device->id, 'episode', $episode->id, $episode->title, 3600);
        \App\Domain\Device\DeviceCache::updateNowPlaying($device->id, new \App\Domain\Media\NowPlaying(track: new \App\Domain\Media\TrackData(name: 'Episode 1', duration: 3600)));
        event(new \App\Events\Device\ProgressUpdated((string) $device->id, 125));

        $this->assertSame(125, PlaybackPositions::secondsFor('episode', $episode->id));

        event(new \App\Events\Device\ProgressUpdated((string) $device->id, 3595));
        $this->assertSame(0, PlaybackPositions::secondsFor('episode', $episode->id));
        $this->assertNotNull($episode->fresh()->played_at);
    }

    public function test_features_profile_gate_and_the_schedule(): void
    {
        $this->getJson('/api/info')->assertOk()->assertJsonPath('features.podcasts', true);

        config(['profile.current' => 'control']);
        $this->getJson('/api/podcasts')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->admin()->postJson('/api/admin/podcasts', ['feed_url' => self::FEED])->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->get('/library/podcasts')->assertRedirect('/not-in-profile/podcasts');
        $this->assertArrayNotHasKey('podcasts', $this->getJson('/api/info')->json('features'));

        $task = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'podcasts:refresh'));
        $this->assertNotNull($task);
        $this->assertFalse($task->filtersPass(app()));
        config(['profile.current' => 'standard']);
        $this->assertTrue($task->filtersPass(app()));
    }
}
