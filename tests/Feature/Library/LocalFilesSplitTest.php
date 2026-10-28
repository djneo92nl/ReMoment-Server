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

    public function test_service_filter_lists_only_services_with_content(): void
    {
        $this->makeLibraryTrack($this->album('Only Nas'), 'a', streamSource: 'dlna:1');

        $this->get('/albums?scope=streaming')->assertOk()->assertDontSee('Streaming service', false);
    }
}
