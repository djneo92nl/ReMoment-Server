<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Models\Client;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesSpotify;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Own music files (`files`, the Local library) are separate from DLNA servers and Spotify. */
class LocalFilesSplitTest extends TestCase
{
    use FakesSpotify;
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(LeadingSource::SETTING, 'all');
    }

    private function album(string $name): Album
    {
        $artist = Artist::create(['name' => "Artist {$name}", 'source' => 'dlna']);

        return Album::create(['artist_id' => $artist->id, 'name' => $name, 'source' => 'dlna']);
    }

    /** @return list<string> */
    private function albumNames(string $scope): array
    {
        return collect($this->getJson("/api/library/albums?scope={$scope}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
    }

    public function test_local_scope_is_only_own_files_and_streaming_is_dlna_plus_spotify(): void
    {
        $this->makeLibraryTrack($this->album('Own'), 'Song');
        $this->makeLibraryTrack($this->album('Served'), 'Song', streamSource: 'dlna:1');
        $this->makeLibraryTrack($this->album('Spot'), 'Song', dlna: false, spotifyId: 'abc');

        $this->assertSame(['Own'], $this->albumNames('local'));
        $this->assertSame(['Own'], $this->albumNames('files'));
        $this->assertSame(['Served'], $this->albumNames('dlna'));
        $this->assertSame(['Spot'], $this->albumNames('spotify'));
        $this->assertSame(['Served', 'Spot'], $this->albumNames('streaming'));
        $this->assertSame(['Own', 'Served', 'Spot'], $this->albumNames('all'));
    }

    public function test_a_track_with_a_files_and_a_dlna_url_shows_in_both_libraries(): void
    {
        $album = $this->album('Both');
        $track = $this->makeLibraryTrack($album, 'Song');
        Metadata::create([
            'metadatable_type' => Track::class, 'metadatable_id' => $track->id,
            'key' => 'dlna_url', 'value' => 'http://nas.test/both.flac', 'type' => 'url', 'source' => 'dlna:1',
        ]);

        $this->assertSame(['Both'], $this->albumNames('files'));
        $this->assertSame(['Both'], $this->albumNames('dlna'));
        $this->assertSame([], $this->albumNames('spotify'));
        $this->assertSame(['files', 'dlna'], $track->fresh()->sources());
    }

    public function test_track_sources_lists_files_dlna_and_spotify(): void
    {
        $album = $this->album('Three');
        $track = $this->makeLibraryTrack($album, 'Song');
        $this->assertSame(['files'], $track->sources());

        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => 'http://nas.test/x.flac', 'type' => 'url', 'source' => 'dlna:2']);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'external_id', 'value' => 'spotify:track:abc', 'type' => 'string', 'source' => 'spotify']);

        $this->assertSame(['files', 'dlna', 'spotify'], $track->fresh()->sources());
        $this->assertSame(['dlna'], $this->makeLibraryTrack($album, 'Other', streamSource: 'dlna:1')->sources());
    }

    public function test_the_migration_moves_dlna_local_to_files(): void
    {
        $album = $this->album('Old');
        $own = $this->makeLibraryTrack($album, 'Own only', streamSource: 'dlna:local');
        $own->update(['source' => 'dlna']);
        $both = $this->makeLibraryTrack($album, 'Both', streamSource: 'dlna:local');
        $both->update(['source' => 'dlna']);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $both->id, 'key' => 'dlna_url', 'value' => 'http://nas.test/b.flac', 'type' => 'url', 'source' => 'dlna:1']);
        $served = $this->makeLibraryTrack($album, 'Served', streamSource: 'dlna:1');
        $served->update(['source' => 'dlna']);
        $client = Client::create([
            'name' => 'Kitchen', 'type' => 'multi', 'status' => 'approved',
            'registration_token' => Client::generateToken(), 'api_token' => Client::generateToken(), 'hidden_sources' => ['dlna'],
        ]);

        (include database_path('migrations/2026_10_16_200000_split_local_files_from_dlna_library.php'))->up();

        $this->assertSame('files', $own->fresh()->source);
        $this->assertSame('dlna', $both->fresh()->source, 'a DLNA server also serves it');
        $this->assertSame('dlna', $served->fresh()->source);
        $this->assertSame(0, Metadata::where('source', 'dlna:local')->count());
        $this->assertSame(['files', 'dlna'], $both->fresh()->sources());
        $this->assertSame(['dlna', 'files'], $client->fresh()->hidden_sources);
    }

    public function test_streaming_scope_offers_a_filter_per_service_and_a_service_scope_keeps_streaming_active(): void
    {
        $dlna = $this->album('Nas Album');
        $this->makeLibraryTrack($dlna, 'a', streamSource: 'dlna:1');
        $spotify = $this->album('Spot Album');
        $this->makeLibraryTrack($spotify, 'b', dlna: false, spotifyId: 'x');

        $this->get('/albums?scope=streaming')->assertOk()
            ->assertSee('Streaming service', false)->assertSee('All streaming')->assertSee('Nas Album')->assertSee('Spot Album');

        $this->get('/albums?scope=dlna')->assertOk()->assertSee('Nas Album')->assertDontSee('Spot Album')->assertSee('Streaming service', false);

        $this->get('/albums?scope=local')->assertOk()->assertDontSee('Streaming service', false);
    }
}
