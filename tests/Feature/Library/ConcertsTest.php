<?php

namespace Tests\Feature\Library;

use App\Domain\Concerts\Concerts;
use App\Domain\Concerts\ConcertSettings;
use App\Jobs\SyncArtistConcerts;
use App\Livewire\ConcertsShelf;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Concert;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\MakesLibraryTracks;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** Upcoming concerts from MusicBrainz events (roadmap 12.6). The fixture is a real event browse (Radiohead, 2026-11-21), its dates moved forward. */
class ConcertsTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;
    use SignsInAsAdmin;

    private const MBID = 'a74b1b7f-71a5-4011-9441-d0b5e4122711';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Sleep::fake();
        config(['profile.current' => 'standard']);
    }

    /** The real response with every event moved to a date in the future (and the 2012 one left in the past). */
    private function events(): array
    {
        $data = json_decode(file_get_contents(base_path('tests/Fixtures/musicbrainz/events-radiohead.json')), true);
        $data['events'][0]['life-span'] = ['begin' => now()->addDays(10)->toDateString(), 'end' => now()->addDays(10)->toDateString(), 'ended' => false];
        $data['events'][2]['life-span'] = ['begin' => now()->addDays(40)->toDateString(), 'end' => now()->addDays(40)->toDateString(), 'ended' => false];   // no place at all
        $data['events'][1]['life-span'] = ['begin' => '2012-06-08', 'end' => '2012-06-08', 'ended' => true];

        return $data;
    }

    private function artist(string $name = 'Radiohead', ?string $mbid = self::MBID): Artist
    {
        $artist = Artist::create(['name' => $name, 'source' => 'files']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => $name.' LP', 'source' => 'files']);
        $this->makeLibraryTrack($album, 'A song');
        $mbid && Metadata::create(['metadatable_type' => Artist::class, 'metadatable_id' => $artist->id, 'key' => 'mbid', 'value' => $mbid, 'type' => 'string', 'source' => 'musicbrainz']);

        return $artist;
    }

    private function concert(Artist $artist, string $name, string $city, int $inDays = 5, ?string $venue = 'Hall', ?string $address = null): Concert
    {
        return Concert::create(['artist_id' => $artist->id, 'event_mbid' => (string) \Illuminate\Support\Str::uuid(), 'name' => $name, 'starts_on' => now()->addDays($inDays)->toDateString(), 'venue' => $venue, 'city' => $city, 'address' => $address]);
    }

    public function test_a_real_event_response_gives_upcoming_events_with_their_place(): void
    {
        $rows = collect($this->events()['events'])->map(fn ($e) => Concerts::parseEvent($e))->filter()->values();

        $this->assertCount(2, $rows);   // the 2012 one has passed
        $this->assertSame(['Worthy Farm', 'Pilton', 'Worthy Farm, Worthy Lane, Pilton BA4 4BY, United Kingdom', 51.155, -2.586], [$rows[0]['venue'], $rows[0]['city'], $rows[0]['address'], $rows[0]['latitude'], $rows[0]['longitude']]);
        $this->assertSame(['Festival', '10:45'], [$rows[0]['type'], $rows[0]['starts_at']]);
        $this->assertNull($rows[1]['venue']);   // an event without a place relation still counts
        $this->assertNull(Concerts::parseEvent(['id' => 'x', 'life-span' => ['begin' => '2027']]), 'a year alone is too vague');
        $this->assertNull(Concerts::parseEvent(['id' => 'x', 'cancelled' => true, 'life-span' => ['begin' => now()->addDay()->toDateString()]]));
    }

    public function test_sync_keeps_upcoming_events_replaces_old_rows_and_marks_the_artist(): void
    {
        Http::fake(['musicbrainz.org/ws/2/event*' => Http::response($this->events())]);
        $artist = $this->artist();
        $stale = $this->concert($artist, 'Cancelled since', 'Nowhere');

        $this->assertTrue(Concerts::sync($artist));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'artist='.self::MBID) && str_contains($r->url(), 'place-rels'));
        $this->assertSame(2, Concert::where('artist_id', $artist->id)->count());
        $this->assertNull(Concert::find($stale->id));
        $this->assertNotNull($artist->metadata()->where('key', Concerts::CHECKED)->value('value'));

        Concerts::sync($artist);   // a second read changes nothing
        $this->assertSame(2, Concert::count());
        $this->assertFalse(Concerts::sync($this->artist('No id', null)));
    }

    public function test_a_failed_read_keeps_what_was_stored(): void
    {
        Http::fake(['musicbrainz.org/*' => Http::response('', 503)]);
        $artist = $this->artist();
        $this->concert($artist, 'Known', 'Amsterdam');

        try {
            Concerts::sync($artist);
            $this->fail('A 503 must throw so the job is retried');
        } catch (\Throwable) {
            $this->assertSame(1, Concert::count());
        }
    }

    public function test_the_command_queues_due_artists_only_when_switched_on_and_drops_past_concerts(): void
    {
        Queue::fake();
        $due = $this->artist('Due');
        $fresh = $this->artist('Fresh');
        Metadata::create(['metadatable_type' => Artist::class, 'metadatable_id' => $fresh->id, 'key' => Concerts::CHECKED, 'value' => now()->toIso8601String(), 'type' => 'string', 'source' => Concerts::SOURCE]);
        $this->artist('Spotify only', null);
        $past = Concert::create(['artist_id' => $due->id, 'event_mbid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Old', 'starts_on' => now()->subDay()->toDateString()]);

        $this->artisan('concerts:sync')->assertSuccessful();
        Queue::assertNothingPushed();   // off by default

        ConcertSettings::setEnabled(true);
        $this->artisan('concerts:sync')->assertSuccessful();

        Queue::assertPushed(SyncArtistConcerts::class, 1);
        Queue::assertPushed(SyncArtistConcerts::class, fn ($job) => $job->artist->is($due));
        $this->assertNull(Concert::find($past->id));
    }

    public function test_the_job_does_nothing_once_the_setting_is_off(): void
    {
        Http::fake();
        (new SyncArtistConcerts($this->artist()))->handle();

        Http::assertNothingSent();
    }

    public function test_the_api_is_opt_in_filters_by_location_and_serves_one_artist(): void
    {
        $a = $this->artist('Radiohead');
        $b = $this->artist('Other');
        $this->concert($a, 'Amsterdam night', 'Amsterdam', 5, 'Ziggo Dome', 'Arena Blvd 1, Amsterdam, Netherlands');
        $this->concert($a, 'London night', 'London', 3, 'O2', 'Peninsula Square, London, United Kingdom');
        $this->concert($b, 'Berlin night', 'Berlin', 9, 'Tempodrom', null);
        Concert::create(['artist_id' => $a->id, 'event_mbid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Past', 'starts_on' => now()->subDays(2)->toDateString()]);

        $this->getJson('/api/library/concerts')->assertOk()->assertExactJson(['enabled' => false, 'location' => null, 'data' => []]);

        ConcertSettings::setEnabled(true);
        $all = $this->getJson('/api/library/concerts')->assertOk()->assertJsonPath('enabled', true)->json();
        $this->assertSame(['London night', 'Amsterdam night', 'Berlin night'], array_column($all['data'], 'name'));   // soonest first, no past
        $this->assertSame('https://musicbrainz.org/event/'.Concert::where('name', 'London night')->value('event_mbid'), $all['data'][0]['url']);

        ConcertSettings::setLocation('Netherlands, berlin');   // a country matches through the address, a city through its column
        $this->assertSame(['Amsterdam night', 'Berlin night'], array_column($this->getJson('/api/library/concerts')->json('data'), 'name'));
        $this->assertSame('Netherlands, berlin', $this->getJson('/api/library/concerts')->json('location'));
        $this->assertCount(3, $this->getJson('/api/library/concerts?all=1')->json('data'));
        $this->assertSame(['London night'], array_column($this->getJson('/api/library/concerts?location=London')->json('data'), 'name'));

        $this->assertSame(['Berlin night'], array_column($this->getJson("/api/library/artists/{$b->id}/concerts")->json('data'), 'name'));
        $this->assertSame(['Amsterdam night'], array_column($this->getJson("/api/library/concerts?artist_id={$a->id}")->json('data'), 'name'));
        $this->getJson('/api/library/artists/9999/concerts')->assertNotFound();
        $this->getJson('/api/library/concerts?limit=0')->assertStatus(422);
    }

    public function test_the_gates_apply(): void
    {
        config(['profile.current' => 'control']);

        $this->getJson('/api/library/concerts')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }

    public function test_the_admin_settings_carry_the_two_switches(): void
    {
        $headers = $this->adminHeaders();

        $this->getJson('/api/admin/library/settings', $headers)->assertOk()->assertJsonPath('concerts_enabled', false)->assertJsonPath('concerts_location', null);
        $this->putJson('/api/admin/library/settings', ['concerts_enabled' => true, 'concerts_location' => '  Netherlands,  Utrecht '], $headers)->assertOk()
            ->assertJsonPath('concerts_enabled', true)->assertJsonPath('concerts_location', 'Netherlands, Utrecht');
        $this->putJson('/api/admin/library/settings', ['concerts_location' => ''], $headers)->assertOk()->assertJsonPath('concerts_location', null)->assertJsonPath('concerts_enabled', true);
        $this->assertSame('1', Setting::get(ConcertSettings::ENABLED));
    }

    public function test_the_shelf_renders_nothing_until_there_is_something_to_show(): void
    {
        $a = $this->artist('Radiohead');
        $this->concert($a, 'Amsterdam night', 'Amsterdam');

        Livewire::test(ConcertsShelf::class, ['artistId' => $a->id])->assertDontSee('Coming up');   // off

        ConcertSettings::setEnabled(true);
        Livewire::test(ConcertsShelf::class, ['artistId' => $a->id])->assertSee('Coming up')->assertSee('Amsterdam night')->assertDontSee('Radiohead');
        Livewire::test(ConcertsShelf::class)->assertSee('Amsterdam night')->assertSee('Radiohead');   // the Discover use names the artist
        Livewire::test(ConcertsShelf::class, ['artistId' => $this->artist('Quiet')->id])->assertDontSee('Coming up');

        $this->get(route('artists.show', $a))->assertOk()->assertSee('Amsterdam night');
    }

    public function test_the_mcp_kind(): void
    {
        $a = $this->artist('Radiohead');
        $this->concert($a, 'Amsterdam night', 'Amsterdam');
        ConcertSettings::setEnabled(true);

        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'concerts'])->assertOk()->assertSee('Amsterdam night');
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'concerts', 'id' => (string) $a->id, 'location' => 'Tokyo'])->assertOk()->assertDontSee('Amsterdam night');
    }
}
