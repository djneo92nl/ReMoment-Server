<?php

namespace Tests\Feature\Library;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Library\LeadingSource;
use App\Domain\Similar\Embeddings;
use App\Livewire\TrackSimilar;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Device;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use App\Support\SelectedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The MCP kinds and the reusable "Sounds like this" Livewire piece of similar tracks (docs/api/similar-tracks.md, docs/frontend/similar-tracks.md). */
class SimilarTracksUiTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
        Cache::flush();
        FakeLibraryPlaybackDriver::reset();
        $this->album = Album::create(['artist_id' => Artist::create(['name' => 'Band', 'source' => 'files'])->id, 'name' => 'LP', 'source' => 'files']);
    }

    private function track(string $name, ?float $degrees): Track
    {
        $track = $this->makeLibraryTrack($this->album, $name);
        $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
        $file = File::create(['storage_location_id' => $location->id, 'path' => "{$track->id}.flac", 'hash' => "h{$track->id}", 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
        TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => "h{$track->id}"]);
        if ($degrees !== null) {
            Embeddings::store($file, "h{$track->id}", array_pad([cos(deg2rad($degrees)), sin(deg2rad($degrees))], Embeddings::DIMENSIONS, 0.0));
        }

        return $track;
    }

    private function device(): Device
    {
        $device = Device::factory()->create(['ip_address' => '10.0.0.1', 'device_name' => 'Kitchen', 'device_driver' => FakeLibraryPlaybackDriver::class]);
        DeviceCache::updateState($device->id, State::Standby);

        return $device;
    }

    public function test_the_mcp_browse_kind_similar_reuses_the_rest_answer(): void
    {
        $seed = $this->track('Seed', 0);
        $this->track('Closest', 10);
        $this->track('Further', 60);

        $rest = $this->getJson("/api/library/tracks/{$seed->id}/similar")->assertOk()->json();
        $tool = ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'similar', 'id' => (string) $seed->id])->assertOk()->assertSee('Closest')->assertSee('"similarity"')->assertSee('"reason"');
        $this->assertSame(['Closest', 'Further'], array_column($rest['data'], 'name'));

        $plain = $this->track('Plain', null);
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'similar', 'id' => (string) $plain->id])->assertHasErrors(['not_analysed']);
        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'similar'])->assertHasErrors();
        unset($tool);
    }

    public function test_the_mcp_play_type_similar_starts_the_radio(): void
    {
        $seed = $this->track('Seed', 0);
        foreach (range(1, 6) as $i) {
            $this->track("Match {$i}", $i * 10);
        }
        $device = $this->device();

        ReMomentServer::tool(Tools\PlayFromLibrary::class, ['device_id' => $device->id, 'type' => 'similar', 'id' => $seed->id])->assertOk()->assertSee('"queued":7');
        $this->assertSame('Seed', FakeLibraryPlaybackDriver::$calls[0]['tracks']->first()->name);
    }

    public function test_the_component_lists_what_sounds_like_the_track_with_its_closeness(): void
    {
        $seed = $this->track('Seed', 0);
        $this->track('Closest', 10);
        $this->track('Further', 60);
        $this->track('Silent', null);

        Livewire::test(TrackSimilar::class, ['trackId' => $seed->id, 'limit' => 5, 'title' => 'Because you played Seed'])
            ->assertSee('Because you played Seed')
            ->assertSeeInOrder(['Closest', '98%', 'Further', '50%'])
            ->assertDontSee('Silent');

        Livewire::test(TrackSimilar::class, ['trackId' => $seed->id, 'limit' => 1])->assertSee('Closest')->assertDontSee('Further');
    }

    public function test_the_component_says_when_there_is_no_sound_data_and_is_empty_outside_full(): void
    {
        $plain = $this->track('Plain', null);
        Livewire::test(TrackSimilar::class, ['trackId' => $plain->id])->assertSee('No sound data for this track yet.');

        $seed = $this->track('Seed', 0);
        $this->track('Closest', 10);
        config(['profile.current' => 'standard']);
        Livewire::test(TrackSimilar::class, ['trackId' => $seed->id])->assertDontSee('Closest');
    }

    public function test_without_a_pinned_player_play_and_radio_say_so(): void
    {
        $seed = $this->track('Seed', 0);
        $this->track('Match', 10);
        $this->device();

        Livewire::test(TrackSimilar::class, ['trackId' => $seed->id])->call('radio')->assertSee('Pick a player in the bar below first.')
            ->call('play', $seed->id)->assertSee('Pick a player in the bar below first.');
        $this->assertSame([], FakeLibraryPlaybackDriver::$calls);
    }

    public function test_play_and_radio_use_the_pinned_player(): void
    {
        $seed = $this->track('Seed', 0);
        $matches = collect(range(1, 6))->map(fn ($i) => $this->track("Match {$i}", $i * 10));
        $device = $this->device();

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id)->test(TrackSimilar::class, ['trackId' => $seed->id])
            ->call('radio')->assertDontSee('Pick a player')
            ->call('play', $matches[2]->id);

        $this->assertSame(['Seed', 'Match 1', 'Match 2', 'Match 3', 'Match 4', 'Match 5', 'Match 6'], FakeLibraryPlaybackDriver::$calls[0]['tracks']->pluck('name')->all());
        $this->assertSame('Match 3', FakeLibraryPlaybackDriver::$calls[1]['tracks']->first()->name);
    }

    public function test_the_album_page_offers_the_component_only_when_embeddings_exist(): void
    {
        $track = $this->track('Seed', null);
        FileAnalysis::query()->update(['waveform' => array_fill(0, 40, 50)]); // the Track details (with Sections, Stems) need a waveform
        $this->get(route('albums.show', $this->album))->assertOk()->assertSee('Stems')->assertDontSee('track-similar', false);

        $this->track('Other', 0);
        $this->get(route('albums.show', $this->album))->assertOk()->assertSee('track-similar', false)->assertSee('Similar');
        $this->assertSame($track->album_id, $this->album->id);
    }
}
