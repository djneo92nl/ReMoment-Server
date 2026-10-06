<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Listeners\Device\StorePlaybackHistory;
use App\Models\Device;
use App\Models\DlnaServer;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Services\Dlna\DlnaLibraryScanner;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use SpotifyWebAPI\SpotifyWebAPI;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class LibraryIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        \App\Models\Setting::set(\App\Domain\Library\LibrarySettings::ADD_PLAYED_TRACKS, '1');
    }

    // --- DLNA scan ---

    private function dlnaServer(int $port = 8200): DlnaServer
    {
        return DlnaServer::create([
            'friendly_name' => 'NAS',
            'ip' => '10.0.0.5',
            'port' => $port,
            'control_url' => "http://10.0.0.5:{$port}/ctl",
        ]);
    }

    /** @param  list<array{id: string, title: string, artist: string, album: string, duration?: string}>  $items */
    private function fakeDlna(array $items): void
    {
        $didl = '<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">';
        foreach ($items as $item) {
            $didl .= sprintf(
                '<item id="%s" parentID="0"><dc:title>%s</dc:title><dc:creator>%s</dc:creator><upnp:album>%s</upnp:album>'
                .'<upnp:class>object.item.audioItem.musicTrack</upnp:class><res duration="%s">http://10.0.0.5/%s.flac</res></item>',
                $item['id'], htmlspecialchars($item['title']), htmlspecialchars($item['artist']), htmlspecialchars($item['album']),
                $item['duration'] ?? '0:03:00.000', $item['id'],
            );
        }
        $didl .= '</DIDL-Lite>';

        // Shaped to what DlnaContentDirectoryClient::parseDidlLite() reads (an unprefixed
        // attribute on the envelope, prefixed Result/TotalMatches).
        $soap = '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><s:Body>'
            .'<u:BrowseResponse xmlns:u="urn:schemas-upnp-org:service:ContentDirectory:1">'
            .'<u:Result>'.htmlspecialchars($didl).'</u:Result><u:NumberReturned>'.count($items).'</u:NumberReturned><u:TotalMatches>'.count($items).'</u:TotalMatches>'
            .'</u:BrowseResponse></s:Body></s:Envelope>';

        Http::fake(['*' => Http::response($soap)]);
    }

    public function test_dlna_scan_reuses_artist_album_and_track_from_spotify(): void
    {
        $artist = Artist::create(['name' => 'The Beatles', 'source' => 'spotify']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Help! (Remastered)', 'source' => 'spotify']);
        $track = Track::create([
            'artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Help! - Remastered 2009',
            'external_id' => 'spotify:track:help', 'source' => 'spotify', 'duration' => 139,
        ]);

        $server = $this->dlnaServer();
        $this->fakeDlna([['id' => '64$1', 'title' => 'Help!', 'artist' => 'Beatles', 'album' => 'Help!', 'duration' => '0:02:18.000']]);

        app(DlnaLibraryScanner::class)->scanServer($server);

        $this->assertSame(1, Artist::count());
        $this->assertSame(1, Album::count());
        $this->assertSame(1, Track::count());

        $track->refresh();
        $this->assertSame('http://10.0.0.5/64$1.flac', $track->getDlnaUrl());
        $this->assertSame('spotify:track:help', LibraryPlayback::spotifyUri($track), 'Spotify stays the second path');
        $this->assertSame('spotify', $track->source);
        $this->assertSame($track->id, LibraryIdentity::findByExternalId($server->id.':64$1', 'dlna')?->id);
    }

    public function test_dlna_rescan_finds_its_tracks_again(): void
    {
        $server = $this->dlnaServer();
        $this->fakeDlna([
            ['id' => '1', 'title' => 'Yellow', 'artist' => 'Coldplay', 'album' => 'Parachutes'],
            ['id' => '2', 'title' => 'Trouble', 'artist' => 'Coldplay', 'album' => 'Parachutes'],
        ]);

        app(DlnaLibraryScanner::class)->scanServer($server);
        app(DlnaLibraryScanner::class)->scanServer($server);

        $this->assertSame(1, Artist::count());
        $this->assertSame(1, Album::count());
        $this->assertSame(2, Track::count());
        $this->assertSame(2, DB::table('metadata')->where('key', 'dlna_url')->count());
    }

    public function test_dlna_tracks_with_one_title_but_different_lengths_stay_apart(): void
    {
        $server = $this->dlnaServer();
        $this->fakeDlna([
            ['id' => '1', 'title' => 'Intro', 'artist' => 'The xx', 'album' => 'xx', 'duration' => '0:02:07.000'],
            ['id' => '2', 'title' => 'Intro', 'artist' => 'The xx', 'album' => 'xx', 'duration' => '0:05:30.000'],
        ]);

        app(DlnaLibraryScanner::class)->scanServer($server);

        $this->assertSame(2, Track::count());
    }

    public function test_a_second_dlna_server_adds_its_stream_to_the_same_track(): void
    {
        $this->fakeDlna([['id' => '1', 'title' => 'Yellow', 'artist' => 'Coldplay', 'album' => 'Parachutes']]);

        app(DlnaLibraryScanner::class)->scanServer($this->dlnaServer(8200));
        app(DlnaLibraryScanner::class)->scanServer($this->dlnaServer(8201));

        $this->assertSame(1, Track::count());
        $this->assertSame(['dlna:1', 'dlna:2'], DB::table('metadata')->where('key', 'dlna_url')->orderBy('source')->pluck('source')->all());
    }

    // --- Spotify import ---

    private function spotifyImport(array $savedTracks): void
    {
        $api = Mockery::mock(SpotifyWebAPI::class);
        $api->shouldReceive('getMySavedTracks')->andReturn([
            'items' => array_map(fn ($t) => ['track' => $t], $savedTracks),
            'total' => count($savedTracks),
        ]);

        $this->mock(SpotifyTokenService::class, function ($mock) use ($api) {
            $mock->shouldReceive('makeApiClient')->andReturn($api);
            $mock->shouldReceive('isConnected')->andReturn(true);
        });

        app(SpotifyLibraryImporter::class)->importSavedTracks();
    }

    private function spotifyTrack(string $id, string $name, string $artist, string $album, int $ms): array
    {
        return [
            'id' => $id, 'name' => $name, 'duration_ms' => $ms,
            'artists' => [['name' => $artist]],
            'album' => ['name' => $album, 'images' => [], 'release_date' => '1965-08-06'],
        ];
    }

    public function test_spotify_import_reuses_records_from_the_dlna_scan(): void
    {
        $artist = Artist::create(['name' => 'Beatles', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Help!', 'source' => 'dlna', 'favorited_at' => now()]);
        $dlna = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Help!', 'external_id' => '1:64$1', 'source' => 'dlna', 'duration' => 138]);

        $this->spotifyImport([$this->spotifyTrack('help', 'Help! - Remastered 2009', 'The Beatles', 'Help! (Remastered)', 139000)]);
        $this->spotifyImport([$this->spotifyTrack('help', 'Help! - Remastered 2009', 'The Beatles', 'Help! (Remastered)', 139000)]);

        $this->assertSame(1, Artist::count());
        $this->assertSame(1, Album::count());
        $this->assertSame(1, Track::count());
        $this->assertNotNull($album->fresh()->favorited_at);
        $this->assertSame('spotify:track:help', LibraryPlayback::spotifyUri($dlna->fresh()));
        $this->assertSame('dlna', $dlna->fresh()->source);
        $this->assertSame('Help!', $dlna->fresh()->name, 'the existing name is kept');
    }

    public function test_spotify_import_keeps_live_albums_apart(): void
    {
        $this->spotifyImport([
            $this->spotifyTrack('a', 'Yellow', 'Coldplay', 'Parachutes', 266000),
            $this->spotifyTrack('b', 'Yellow - Live', 'Coldplay', 'Parachutes (Live)', 280000),
        ]);

        $this->assertSame(1, Artist::count());
        $this->assertSame(2, Album::count());
        $this->assertSame(2, Track::count());
    }

    // --- Play history ---

    private function device(): Device
    {
        return Device::firstOrCreate(['ip_address' => '10.0.0.1'], [
            'device_name' => 'Speaker', 'device_brand_name' => 'Test', 'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);
    }

    private function play(string $track, string $artist, ?string $album, ?string $source = null, ?string $id = null, ?int $duration = 180, array $meta = []): void
    {
        $artistData = new ArtistData(name: $artist);
        $nowPlaying = new NowPlaying(
            track: new TrackData(id: $id, name: $track, source: $source, artist: $artistData, duration: $duration, meta: $meta),
            album: $album !== null ? new AlbumData(name: $album, artist: $artistData) : null,
            type: 'music',
            platform: 'media',
        );

        (new StorePlaybackHistory)->handle(new NowPlayingUpdated((string) $this->device()->id, $nowPlaying, 'media'));
    }

    public function test_a_speaker_play_counts_for_the_library_track(): void
    {
        $artist = Artist::create(['name' => 'Coldplay', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Parachutes', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Yellow', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 266]);

        $this->play('YELLOW', 'coldplay', 'Parachutes (Deluxe Edition)', duration: 267);

        $this->assertSame(1, Artist::count());
        $this->assertSame(1, Album::count());
        $this->assertSame(1, Track::count());
        $this->assertSame($track->id, Play::first()->track_id);
    }

    public function test_a_spotify_play_is_found_by_its_uri(): void
    {
        $artist = Artist::create(['name' => 'Daft Punk', 'source' => 'spotify']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Random Access Memories', 'source' => 'spotify']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Get Lucky (feat. Pharrell Williams)', 'external_id' => 'spotify:track:lucky', 'source' => 'spotify']);

        $this->play('Get Lucky (feat. Pharrell Williams)', 'Daft Punk', 'Random Access Memories', 'spotify', 'spotify:track:lucky', 369);

        $this->assertSame(1, Track::count());
        $this->assertSame($track->id, Play::first()->track_id);
        $this->assertSame(369, $track->fresh()->duration);
    }

    public function test_a_speaker_playing_spotify_records_the_uri_from_its_meta(): void
    {
        $this->play('Yellow', 'Coldplay', 'Parachutes', 'spotify', null, 266, ['spotifyId' => 'spotify:track:yellow']);

        $this->assertSame('spotify:track:yellow', LibraryPlayback::spotifyUri(Track::first()));
    }

    public function test_a_radio_play_without_album_joins_the_album_track(): void
    {
        $artist = Artist::create(['name' => 'Coldplay', 'source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Parachutes', 'source' => 'dlna']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Yellow', 'source' => 'dlna', 'external_id' => '1:1', 'duration' => 266]);

        $this->play('Yellow', 'Coldplay', null, duration: null);

        $this->assertSame(1, Track::count());
        $this->assertSame($track->id, Play::first()->track_id);
    }

    public function test_an_album_less_track_gets_its_album_on_a_later_play(): void
    {
        $this->play('Yellow', 'Coldplay', null);
        // Consecutive reports of the same track count as one play; forget it, as a later play would.
        \Illuminate\Support\Facades\Cache::flush();
        $this->play('Yellow', 'Coldplay', 'Parachutes');

        $this->assertSame(1, Track::count());
        $this->assertNotNull(Track::first()->album_id);
    }

    public function test_feat_credits_in_the_artist_resolve_to_the_main_artist(): void
    {
        $this->play('Get Lucky', 'Daft Punk', 'Random Access Memories');
        $this->play('Get Lucky', 'Daft Punk feat. Pharrell Williams', 'Random Access Memories');

        $this->assertSame(['Daft Punk'], Artist::pluck('name')->all());
        $this->assertSame(1, Track::count());
    }

    public function test_rows_from_before_keys_existed_are_found(): void
    {
        $artist = Artist::create(['name' => 'Coldplay', 'source' => 'dlna']);
        DB::table('artists')->update(['name_key' => null]);

        $this->assertSame($artist->id, LibraryIdentity::artist('COLDPLAY', 'spotify')->id);
        $this->assertSame('coldplay', DB::table('artists')->value('name_key'));
    }
}
