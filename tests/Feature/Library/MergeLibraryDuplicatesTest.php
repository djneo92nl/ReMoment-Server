<?php

namespace Tests\Feature\Library;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Library\LibraryPlayback;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CachesArtwork;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class MergeLibraryDuplicatesTest extends TestCase
{
    use CachesArtwork;
    use RefreshDatabase;

    private const SPOTIFY_COVER = 'https://i.scdn.co/image/help';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function device(): Device
    {
        return Device::firstOrCreate(['ip_address' => '10.0.0.1'], [
            'device_name' => 'Speaker', 'device_brand_name' => 'Test', 'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);
    }

    private function meta(object $model, string $key, ?string $value, string $source): Metadata
    {
        return Metadata::create([
            'metadatable_type' => $model::class, 'metadatable_id' => $model->id,
            'key' => $key, 'value' => $value, 'source' => $source, 'type' => 'string',
        ]);
    }

    /**
     * The same song from a DLNA scan and a Spotify import, made with the
     * pre-identity behaviour (one record per source).
     *
     * @return array<string, Artist|Album|Track|Playlist>
     */
    private function duplicates(): array
    {
        $dlnaArtist = Artist::create(['name' => 'Beatles', 'source' => 'dlna']);
        $dlnaAlbum = Album::create(['artist_id' => $dlnaArtist->id, 'name' => 'Help!', 'source' => 'dlna', 'favorited_at' => '2026-05-01 10:00:00']);
        $dlnaTrack = Track::create(['artist_id' => $dlnaArtist->id, 'album_id' => $dlnaAlbum->id, 'name' => 'Help!', 'external_id' => '1:64$1', 'source' => 'dlna', 'duration' => 138]);
        $this->meta($dlnaTrack, 'dlna_url', 'http://nas/help.flac', 'dlna:1');
        $this->meta($dlnaTrack, 'lyrics_plain', '', 'lrclib');

        $spotifyArtist = Artist::create(['name' => 'The Beatles', 'source' => 'spotify', 'favorited_at' => '2026-02-01 10:00:00']);
        $spotifyAlbum = Album::create([
            'artist_id' => $spotifyArtist->id, 'name' => 'Help! (Remastered)', 'source' => 'spotify',
            'images' => [['url' => self::SPOTIFY_COVER]], 'colors' => ['#112233'], 'released_at' => '1965-08-06',
            'favorited_at' => '2026-03-01 10:00:00',
        ]);
        $spotifyTrack = Track::create(['artist_id' => $spotifyArtist->id, 'album_id' => $spotifyAlbum->id, 'name' => 'Help! - Remastered 2009', 'external_id' => 'spotify:track:help', 'source' => 'spotify', 'duration' => 139]);
        $this->meta($spotifyTrack, 'lyrics_plain', 'Help, I need somebody', 'lrclib');
        $this->meta($spotifyAlbum, 'spotify_album_uri', 'spotify:album:help', 'spotify');
        $this->meta($spotifyArtist, 'bio', 'From Liverpool.', 'lastfm');

        $this->cacheProcessedArtwork(self::SPOTIFY_COVER, ['colors' => ['#112233'], 'safe_colors' => ['#99aabb']]);

        $playlist = Playlist::create(['name' => 'Mix', 'source' => 'local']);
        $playlist->tracks()->attach([$dlnaTrack->id => ['position' => 0], $spotifyTrack->id => ['position' => 1]]);
        $other = Playlist::create(['name' => 'Spotify mix', 'source' => 'local']);
        $other->tracks()->attach([$spotifyTrack->id => ['position' => 0]]);

        $device = $this->device();
        Play::create(['device_id' => $device->id, 'track_id' => $dlnaTrack->id, 'source_type' => 'dlna', 'played_at' => now()->subDay()]);
        Play::create(['device_id' => $device->id, 'track_id' => $spotifyTrack->id, 'source_type' => 'spotify', 'played_at' => now()]);

        return compact('dlnaArtist', 'dlnaAlbum', 'dlnaTrack', 'spotifyArtist', 'spotifyAlbum', 'spotifyTrack', 'playlist', 'other');
    }

    public function test_duplicates_are_merged_into_the_oldest_record(): void
    {
        $r = $this->duplicates();

        $this->artisan('library:merge-duplicates', ['--force' => true])->assertSuccessful();

        $this->assertSame([$r['dlnaArtist']->id], Artist::pluck('id')->all());
        $this->assertSame([$r['dlnaAlbum']->id], Album::pluck('id')->all());
        $this->assertSame([$r['dlnaTrack']->id], Track::pluck('id')->all());

        $album = Album::first();
        $this->assertSame($r['dlnaArtist']->id, $album->artist_id);
        $this->assertSame([['url' => self::SPOTIFY_COVER]], $album->images, 'the processed cover wins');
        $this->assertSame(['#112233'], $album->colors);
        $this->assertSame('1965-08-06', $album->released_at->toDateString());
        $this->assertTrue(Carbon::parse('2026-03-01 10:00:00')->equalTo($album->favorited_at), 'earliest favorite kept');
        $this->assertSame('spotify:album:help', $album->metadata()->where('key', 'spotify_album_uri')->value('value'));

        $artist = Artist::first();
        $this->assertTrue(Carbon::parse('2026-02-01 10:00:00')->equalTo($artist->favorited_at));
        $this->assertSame('From Liverpool.', $artist->bio());

        $track = Track::first();
        $this->assertSame('http://nas/help.flac', $track->getDlnaUrl(), 'DLNA first');
        $this->assertSame('spotify:track:help', LibraryPlayback::spotifyUri($track), 'Spotify second');
        $this->assertSame('Help, I need somebody', $track->lyricsPlain(), 'an empty value is filled from the duplicate');
        $this->assertSame(1, $track->metadata()->where('key', 'lyrics_plain')->count());

        $this->assertSame([$track->id, $track->id], Play::pluck('track_id')->all());
        $this->assertSame([$track->id], $r['playlist']->tracks()->pluck('tracks.id')->all(), 'one entry per playlist');
        $this->assertSame([$track->id], $r['other']->tracks()->pluck('tracks.id')->all());

        $this->assertSame(0, Metadata::query()->whereNotIn('metadatable_id', [$track->id, $album->id, $artist->id])->count(), 'nothing left on deleted records');
    }

    public function test_merged_library_still_feeds_artwork_and_stats(): void
    {
        $this->duplicates();

        $this->artisan('library:merge-duplicates', ['--force' => true])->assertSuccessful();

        $this->assertSame([self::SPOTIFY_COVER], LibraryArtwork::recentCoverUrls()->all());
        $this->assertSame(1, LibraryArtwork::albumsByRecency()->count());
        $this->assertSame(2, Album::first()->plays()->count());
        $this->assertSame(2, Artist::first()->plays()->count());

        $this->getJson('/api/library/albums/'.Album::first()->id)->assertOk()->assertJsonPath('tracks.0.playable', true);
    }

    public function test_the_ids_of_merged_records_are_gone(): void
    {
        $r = $this->duplicates();

        $this->artisan('library:merge-duplicates', ['--force' => true])->assertSuccessful();

        $this->getJson('/api/library/albums/'.$r['spotifyAlbum']->id)->assertNotFound();
        $this->getJson('/api/library/artists/'.$r['spotifyArtist']->id)->assertNotFound();
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->duplicates();
        DB::table('artists')->update(['name_key' => null]);
        $before = $this->snapshot();

        $this->artisan('library:merge-duplicates', ['--dry-run' => true])
            ->expectsOutputToContain('keep #1 Beatles (dlna)')
            ->expectsOutputToContain('← #2 The Beatles (spotify)')
            ->expectsOutputToContain('1 artist, 1 album and 1 track group(s) to merge.')
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_is_idempotent(): void
    {
        $this->duplicates();

        $this->artisan('library:merge-duplicates', ['--force' => true])->assertSuccessful();
        $after = $this->snapshot();

        $this->artisan('library:merge-duplicates', ['--force' => true])
            ->expectsOutputToContain('No duplicates found.')
            ->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->duplicates();
        $before = $this->snapshot();

        $this->artisan('library:merge-duplicates')
            ->expectsConfirmation('Merge these? Ids of merged-away records stop existing.', 'no')
            ->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_different_records_are_left_alone(): void
    {
        $duo = Artist::create(['name' => 'Simon & Garfunkel', 'source' => 'dlna']);
        Artist::create(['name' => 'Simon', 'source' => 'spotify']);
        $coldplay = Artist::create(['name' => 'Coldplay', 'source' => 'dlna']);
        $studio = Album::create(['artist_id' => $coldplay->id, 'name' => 'Parachutes', 'source' => 'dlna']);
        Album::create(['artist_id' => $coldplay->id, 'name' => 'Parachutes (Live)', 'source' => 'spotify']);
        Album::create(['artist_id' => $duo->id, 'name' => 'Parachutes', 'source' => 'dlna']);
        Track::create(['artist_id' => $coldplay->id, 'album_id' => $studio->id, 'name' => 'Intro', 'source' => 'dlna', 'external_id' => '1:1', 'duration' => 60]);
        Track::create(['artist_id' => $coldplay->id, 'album_id' => $studio->id, 'name' => 'Intro', 'source' => 'dlna', 'external_id' => '1:2', 'duration' => 200]);
        Track::create(['artist_id' => $coldplay->id, 'album_id' => $studio->id, 'name' => 'Yellow - Live', 'source' => 'dlna', 'external_id' => '1:3']);
        Track::create(['artist_id' => $coldplay->id, 'album_id' => $studio->id, 'name' => 'Yellow', 'source' => 'dlna', 'external_id' => '1:4']);

        $this->artisan('library:merge-duplicates', ['--force' => true])
            ->expectsOutputToContain('No duplicates found.')
            ->assertSuccessful();

        $this->assertSame(3, Artist::count());
        $this->assertSame(3, Album::count());
        $this->assertSame(4, Track::count());
    }

    public function test_an_album_less_play_track_merges_into_the_album_track(): void
    {
        $artist = Artist::create(['name' => 'Coldplay', 'source' => 'dlna']);
        $radioArtist = Artist::create(['name' => 'COLDPLAY', 'source' => null]);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Parachutes', 'source' => 'dlna']);
        $albumTrack = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Yellow', 'source' => 'dlna', 'external_id' => '1:1', 'duration' => 266]);
        $radioTrack = Track::create(['artist_id' => $radioArtist->id, 'name' => 'yellow', 'source' => null]);
        $radioTrack2 = Track::create(['artist_id' => $radioArtist->id, 'name' => 'Yellow', 'source' => 'radio', 'external_id' => 'radio:yellow']);
        Play::create(['device_id' => $this->device()->id, 'track_id' => $radioTrack->id, 'source_type' => 'radio', 'played_at' => now()]);

        $this->artisan('library:merge-duplicates', ['--force' => true])->assertSuccessful();

        $this->assertSame([$albumTrack->id], Track::pluck('id')->all());
        $this->assertSame($albumTrack->id, Play::first()->track_id);
        $this->assertSame('radio:yellow', $albumTrack->metadata()->where('key', 'external_id')->value('value'));
        $this->assertSame(1, Artist::count());
        $this->assertNull(Track::find($radioTrack2->id));
    }

    public function test_a_survivor_without_external_id_takes_the_duplicates(): void
    {
        $artist = Artist::create(['name' => 'Coldplay', 'source' => null]);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Parachutes', 'source' => null]);
        $played = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Yellow', 'source' => null]);
        Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Yellow', 'source' => 'spotify', 'external_id' => 'spotify:track:yellow']);

        $this->artisan('library:merge-duplicates', ['--force' => true])->assertSuccessful();

        $played->refresh();
        $this->assertSame('spotify:track:yellow', $played->external_id);
        $this->assertSame('spotify', $played->source);
        $this->assertSame(1, Track::count());
    }

    public function test_artists_with_the_same_album_under_both_are_merged(): void
    {
        // Same name and source under two artists that merge: albums is unique on (artist_id, name, source).
        $a = Artist::create(['name' => 'Beatles', 'source' => 'dlna']);
        $b = Artist::create(['name' => 'The Beatles', 'source' => 'dlna']);
        Album::create(['artist_id' => $a->id, 'name' => 'Help!', 'source' => 'dlna']);
        $loser = Album::create(['artist_id' => $b->id, 'name' => 'Help!', 'source' => 'dlna']);
        Track::create(['artist_id' => $b->id, 'album_id' => $loser->id, 'name' => 'Help!', 'source' => 'dlna', 'external_id' => '1:1']);

        $this->artisan('library:merge-duplicates', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, Artist::count());
        $this->assertSame(1, Album::count());
        $this->assertSame(Album::first()->id, Track::first()->album_id);
        $this->assertSame($a->id, Track::first()->artist_id);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return collect(['artists', 'albums', 'tracks', 'metadata', 'plays', 'playlist_track'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])
            ->all();
    }
}
