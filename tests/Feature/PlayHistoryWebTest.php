<?php

namespace Tests\Feature;

use App\Domain\Stats\PlayHistory;
use App\Domain\Stats\PlayRetention;
use App\Livewire\PlayHistory as PlayHistoryComponent;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** /history, the album and artist links, the retention and the MCP tool all sit on App\Domain\Stats\PlayHistory (docs/api/history.md). */
class PlayHistoryWebTest extends TestCase
{
    use RefreshDatabase;

    private ?int $device = null;

    private function track(string $name, ?Album $album = null, ?Artist $artist = null): Track
    {
        $artist ??= Artist::create(['name' => 'Band '.$name]);
        $album ??= Album::create(['name' => 'LP '.$name, 'artist_id' => $artist->id]);

        return Track::create(['name' => $name, 'artist_id' => $artist->id, 'album_id' => $album->id, 'source' => 'dlna', 'external_id' => 'x:'.$name]);
    }

    private function play(array $attributes = []): Play
    {
        return Play::create($attributes + ['device_id' => $this->device ??= Device::factory()->create()->id, 'played_at' => now(), 'source_type' => 'dlna']);
    }

    public function test_page_agrees_with_the_api_list(): void
    {
        $device = Device::factory()->create();
        $a = $this->track('Alpha');
        $this->play(['track_id' => $a->id, 'device_id' => $device->id, 'played_at' => now()->subHour()]);
        $this->play(['track_name' => 'Ghost', 'artist_name' => 'Nobody', 'played_at' => now()]);

        $api = $this->getJson('/api/library/history')->assertOk()->json('data');
        $names = collect($api)->pluck('name')->all();

        $component = Livewire::test(PlayHistoryComponent::class);
        foreach ($names as $name) {
            $component->assertSee($name);
        }

        $component->set('match', 'library')->assertSee('Alpha')->assertDontSee('Ghost');
        $component->set('match', 'unknown')->assertSee('Ghost')->assertDontSee('Alpha');
        $this->assertSame(['Alpha'], collect($this->getJson('/api/library/history?match=library')->json('data'))->pluck('name')->all());
        $this->assertSame(['Ghost'], collect($this->getJson('/api/library/history?match=unknown')->json('data'))->pluck('name')->all());
    }

    public function test_filters_device_source_and_dates(): void
    {
        $one = Device::factory()->create(['device_name' => 'One']);
        $two = Device::factory()->create(['device_name' => 'Two']);
        $this->play(['track_name' => 'OnOne', 'device_id' => $one->id, 'played_at' => '2026-01-10 10:00:00']);
        $this->play(['track_name' => 'OnTwo', 'device_id' => $two->id, 'source_type' => 'spotify', 'played_at' => '2026-02-10 10:00:00']);

        Livewire::test(PlayHistoryComponent::class)
            ->set('deviceId', $two->id)->assertSee('OnTwo')->assertDontSee('OnOne')
            ->set('deviceId', null)->set('sourceFilter', 'dlna')->assertSee('OnOne')->assertDontSee('OnTwo')
            ->set('sourceFilter', null)->set('dateFrom', '2026-02-01')->assertSee('OnTwo')->assertDontSee('OnOne')
            ->set('dateFrom', '')->set('dateTo', '2026-01-31')->assertSee('OnOne')->assertDontSee('OnTwo');
    }

    public function test_cursor_paging_goes_forward_and_back(): void
    {
        foreach (range(1, PlayHistory::PAGE + 5) as $i) {
            $this->play(['track_name' => sprintf('Song%03d', $i), 'played_at' => now()->subMinutes(1000 - $i)]);
        }

        Livewire::test(PlayHistoryComponent::class)
            ->assertSee('Song055')->assertDontSee('Song005')
            ->call('nextPage')->assertSee('Song005')->assertDontSee('Song055')
            ->call('previousPage')->assertSee('Song055');
    }

    public function test_album_and_artist_filters_show_their_plays_and_pages_link_to_them(): void
    {
        $artist = Artist::create(['name' => 'Solo']);
        $album = Album::create(['name' => 'Record', 'artist_id' => $artist->id]);
        $mine = $this->track('Mine', $album, $artist);
        $other = $this->track('Other');
        $this->play(['track_id' => $mine->id]);
        $this->play(['track_id' => $other->id]);

        Livewire::withQueryParams(['albumId' => $album->id])->test(PlayHistoryComponent::class)
            ->assertSee('Mine')->assertDontSee('Other')->assertSee('the album Record');
        Livewire::withQueryParams(['artistId' => $artist->id])->test(PlayHistoryComponent::class)
            ->assertSee('Mine')->assertDontSee('Other');

        $this->get(route('albums.show', $album))->assertOk()->assertSee(route('history.index', ['albumId' => $album->id]), false);
        $this->get(route('artists.show', $artist))->assertOk()->assertSee(route('history.index', ['artistId' => $artist->id]), false);
    }

    public function test_retention_default_is_forever_and_prune_keeps_tracks(): void
    {
        $track = $this->track('Old');
        $this->play(['track_id' => $track->id, 'played_at' => now()->subMonths(30)]);
        $this->play(['track_id' => $track->id, 'played_at' => now()->subDays(3)]);

        $this->assertNull(PlayRetention::months());
        $this->artisan('history:prune')->assertSuccessful();
        $this->assertSame(2, Play::count());

        PlayRetention::setMonths(12);
        $this->artisan('history:prune --dry-run')->expectsOutputToContain('1 plays older than 12 months')->assertSuccessful();
        $this->assertSame(2, Play::count());

        $this->artisan('history:prune')->assertSuccessful();
        $this->assertSame(1, Play::count());
        $this->assertSame(1, Track::count());

        PlayRetention::setMonths(null);
        $this->assertNull(PlayRetention::months());
    }

    public function test_admin_saves_the_retention_from_the_history_page(): void
    {
        $this->get(route('history.index'))->assertOk()->assertDontSee('Keep history for');
        $this->post(route('settings.history.save'), ['keep_months' => 6])->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get(route('history.index'))->assertSee('Keep history for');
        $this->post(route('settings.history.save'), ['keep_months' => 6])->assertRedirect(route('history.index'));
        $this->assertSame(6, PlayRetention::months());
        $this->post(route('settings.history.save'), ['keep_months' => ''])->assertRedirect();
        $this->assertNull(PlayRetention::months());
    }

    public function test_mcp_tool_goes_through_the_api(): void
    {
        $track = $this->track('Tooled');
        $this->play(['track_id' => $track->id]);
        $this->play(['track_name' => 'Textual']);

        ReMomentServer::tool(Tools\PlaybackHistory::class, [])->assertOk()->assertSee(['Tooled', 'Textual']);
        ReMomentServer::tool(Tools\PlaybackHistory::class, ['match' => 'unknown'])->assertOk()->assertSee('Textual')->assertDontSee('Tooled');
        ReMomentServer::tool(Tools\PlaybackHistory::class, ['album_id' => $track->album_id])->assertOk()->assertSee('Tooled')->assertDontSee('Textual');
        ReMomentServer::tool(Tools\PlaybackHistory::class, ['cursor' => 'nonsense'])->assertHasErrors();
    }
}
