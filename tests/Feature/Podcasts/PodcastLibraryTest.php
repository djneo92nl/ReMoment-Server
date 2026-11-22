<?php

namespace Tests\Feature\Podcasts;

use App\Domain\Device\State;
use App\Domain\Podcasts\Podcasts;
use App\Livewire\ContinueListening;
use App\Livewire\PodcastLibrary;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\AdminToken;
use App\Models\Device;
use App\Models\PlaybackPosition;
use App\Models\Podcast;
use App\Models\User;
use App\Support\SelectedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeSeekingStreamDriver;
use Tests\Support\FakeStreamDriver;
use Tests\Support\MakesPodcastFeeds;
use Tests\TestCase;

/** The Podcasts page under Library (docs/frontend/podcasts.md), the Continue listening row of an episode and the MCP tool. */
class PodcastLibraryTest extends TestCase
{
    use MakesPodcastFeeds;
    use RefreshDatabase;

    private const FEED = 'https://show.test/feed.xml';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        FakeStreamDriver::$played = [];
    }

    private function subscribed(int $episodes = 3): Podcast
    {
        $this->fakeFeed(self::FEED, $this->feedXml('The Show', array_map(fn ($n) => ['title' => "Episode {$n}"], range(1, $episodes))));

        return Podcasts::subscribe(self::FEED)[0];
    }

    public function test_the_page_lists_subscriptions_and_new_episodes_for_a_guest(): void
    {
        $this->subscribed(2);

        $this->get(route('library.podcasts'))->assertOk()->assertSee('The Show')->assertSee('2 new')->assertSee('Episode 2')
            ->assertSee('Sign in')->assertDontSee('Add podcast');
        $this->get(route('library.index'))->assertOk()->assertSee('Podcasts');
    }

    public function test_an_empty_page_says_how_to_start(): void
    {
        Livewire::test(PodcastLibrary::class)->assertSee('No podcasts yet');
    }

    public function test_selecting_a_podcast_shows_its_episodes_and_marks_them_played(): void
    {
        $podcast = $this->subscribed(3);
        $episode = $podcast->episodes()->orderBy('published_at')->first();

        Livewire::test(PodcastLibrary::class)
            ->call('select', $podcast->id)->assertSee('Episode 1')->assertSee('Only unplayed')
            ->call('togglePlayed', $episode->id);

        $this->assertNotNull($episode->fresh()->played_at);

        Livewire::test(PodcastLibrary::class)->call('select', $podcast->id)->call('markAllPlayed', $podcast->id);
        $this->assertSame(0, $podcast->episodes()->whereNull('played_at')->count());
        Livewire::test(PodcastLibrary::class)->assertSee('Nothing new');
    }

    public function test_adding_refreshing_and_removing_need_the_admin_login(): void
    {
        $podcast = $this->subscribed(1);

        Livewire::test(PodcastLibrary::class)->set('feedUrl', self::FEED)->call('subscribe')->assertForbidden();
        Livewire::test(PodcastLibrary::class)->call('refresh', $podcast->id)->assertForbidden();
        Livewire::test(PodcastLibrary::class)->call('unsubscribe', $podcast->id)->assertForbidden();
        $this->assertDatabaseCount('podcasts', 1);
    }

    public function test_the_admin_adds_and_removes_a_podcast(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeFeed('https://other.test/rss', $this->feedXml('Other Show', [['title' => 'Only']]));

        Livewire::test(PodcastLibrary::class)->assertSee('Add podcast')
            ->set('feedUrl', 'https://other.test/rss')->call('subscribe')->assertSee('Subscribed to Other Show.')->assertSet('feedUrl', '');
        $podcast = Podcast::firstOrFail();

        Livewire::test(PodcastLibrary::class)->set('feedUrl', 'https://nope.test/x')->call('subscribe')->assertSee('The feed answered HTTP 404.')
            ->call('unsubscribe', $podcast->id)->assertSee('Removed Other Show.');
        $this->assertDatabaseCount('podcasts', 0);
    }

    public function test_play_says_so_without_a_pinned_player(): void
    {
        $episode = $this->subscribed(1)->episodes()->first();

        Livewire::test(PodcastLibrary::class)->call('play', $episode->id)->assertSet('notice', 'Pick a player in the bar below first.')->assertSet('noticeIsError', true);
        $this->assertSame([], FakeStreamDriver::$played);
    }

    public function test_play_uses_the_pinned_player(): void
    {
        $episode = $this->subscribed(1)->episodes()->first();
        $device = Device::factory()->driver(FakeStreamDriver::class)->inState(State::Standby)->create();

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id);
        Livewire::test(PodcastLibrary::class)->call('play', $episode->id)->assertSet('notice', null);

        $this->assertSame('https://cdn.test/1.mp3', FakeStreamDriver::$played[0][0]);
    }

    public function test_a_pinned_player_that_cannot_stream_gets_a_readable_error(): void
    {
        $episode = $this->subscribed(1)->episodes()->first();
        $plain = Device::factory()->inState(State::Standby)->create();

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $plain->id);
        Livewire::test(PodcastLibrary::class)->call('play', $episode->id)->assertSet('noticeIsError', true)->assertSee('does not support stream_url');
    }

    public function test_continue_listening_shows_and_resumes_an_episode(): void
    {
        $episode = $this->subscribed(1)->episodes()->first();
        PlaybackPosition::create(['kind' => 'episode', 'item_id' => $episode->id, 'position' => 1200, 'duration' => 3600]);
        $device = Device::factory()->driver(FakeSeekingStreamDriver::class)->inState(State::Standby)->create();

        Livewire::test(ContinueListening::class)->assertSee('Continue listening')->assertSee('Episode 1')->assertSee('The Show')->assertSee('20:00');

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id);
        Livewire::test(ContinueListening::class)->call('resume', 'episode', $episode->id)->assertSet('notice', null);

        $this->assertSame('https://cdn.test/1.mp3', FakeStreamDriver::$played[0][0]);
        Queue::assertPushed(\App\Jobs\ResumePlayback::class, fn ($job) => $job->position === 1200 && $job->kind === 'episode');
    }

    public function test_the_mcp_tool_wraps_the_api_and_keeps_subscribing_for_the_admin(): void
    {
        $this->fakeFeed(self::FEED, $this->feedXml('The Show', [['title' => 'Episode 1'], ['title' => 'Episode 2']]));

        ReMomentServer::tool(Tools\Podcasts::class, ['action' => 'subscribe', 'feed_url' => self::FEED])->assertHasErrors(['unauthenticated']);

        User::factory()->create();
        [, $plain] = AdminToken::issue('mcp');
        request()->headers->set('Authorization', 'Bearer '.$plain);
        ReMomentServer::tool(Tools\Podcasts::class, ['action' => 'subscribe', 'feed_url' => self::FEED])->assertOk()->assertSee('The Show');

        $podcast = Podcast::firstOrFail();
        ReMomentServer::tool(Tools\Podcasts::class, ['action' => 'list'])->assertOk()->assertSee('The Show');
        ReMomentServer::tool(Tools\Podcasts::class, ['action' => 'episodes', 'podcast_id' => $podcast->id, 'unplayed' => true, 'limit' => 1])->assertOk()->assertSee('Episode 2')->assertDontSee('Episode 1');

        $episode = $podcast->episodes()->orderBy('published_at')->first();
        ReMomentServer::tool(Tools\Podcasts::class, ['action' => 'mark_played', 'episode_id' => $episode->id])->assertOk();
        $this->assertNotNull($episode->fresh()->played_at);
        ReMomentServer::tool(Tools\Podcasts::class, ['action' => 'mark_all_played', 'podcast_id' => $podcast->id])->assertOk();

        ReMomentServer::tool(Tools\Podcasts::class, ['action' => 'unsubscribe', 'podcast_id' => $podcast->id])->assertHasErrors(['confirm=true']);
        ReMomentServer::tool(Tools\Podcasts::class, ['action' => 'unsubscribe', 'podcast_id' => $podcast->id, 'confirm' => true])->assertOk();
        $this->assertDatabaseCount('podcasts', 0);
    }
}
