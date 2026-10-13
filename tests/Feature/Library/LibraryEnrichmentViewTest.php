<?php

namespace Tests\Feature\Library;

use App\Domain\Library\AlbumSound;
use App\Domain\Library\Enrichment;
use App\Domain\Library\GenreSync;
use App\Domain\Library\LeadingSource;
use App\Models\Device;
use App\Models\File;
use App\Models\FileAnalysis;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The Library and Albums pages with the enrichment: genres, release details, sound, discover rows, filters. */
class LibraryEnrichmentViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Setting::set(LeadingSource::SETTING, 'all');
    }

    private function album(string $artistName, string $name, array $meta = [], ?array $analysis = null, ?string $released = null): Album
    {
        $artist = Artist::firstOrCreate(['name' => $artistName], ['source' => 'dlna']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => $name, 'source' => 'dlna', 'released_at' => $released]);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => "{$name} song", 'external_id' => "x:{$name}", 'source' => 'dlna', 'duration' => 3600]);

        foreach ($meta as $key => $value) {
            Enrichment::save($album, $key, $value, 'string', 'test');
        }

        if ($analysis !== null) {
            $location = StorageLocation::first() ?? StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => '/tmp'], 'role' => 'library']);
            $file = File::create(['storage_location_id' => $location->id, 'path' => "{$name}.flac", 'hash' => $name, 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
            TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
            FileAnalysis::create(['file_id' => $file->id, 'content_hash' => $name, 'loudness_lufs' => -9.0] + $analysis);
        }

        return $album;
    }

    public function test_album_cards_show_release_details_genres_sound_and_listeners(): void
    {
        $album = $this->album('Band', 'Parachutes', ['label' => 'Parlophone', 'release_type' => 'album', 'lastfm_listeners' => '1500000', 'rating' => '8.6'],
            ['bpm' => 124.0, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.8]]], '2000-07-10');
        GenreSync::sync($album, ['alternative rock'], 'test');

        $this->get('/library')->assertOk()
            ->assertSee('Album · 2000 · Parlophone')->assertSee('Alternative Rock')
            ->assertSee('124 BPM · -9 LUFS')->assertSee('★ 8.6')->assertSee('1.5M listeners');

        $this->get('/albums')->assertOk()->assertSee('Album · 2000 · Parlophone')->assertSee('124 BPM');
    }

    public function test_the_library_page_lists_genres_glance_and_discover_rows(): void
    {
        $a = $this->album('Band', 'Parachutes', ['rating' => '9.0'], ['bpm' => 80.0, 'mood_tags' => [['name' => 'sad', 'score' => 0.7]]], '1999-01-01');
        $b = $this->album('Other', 'Fast One', [], ['bpm' => 150.0, 'mood_tags' => [['name' => 'party', 'score' => 0.9]]], '2011-05-05');
        $c = $this->album('Cousin', 'Familiar', [], null, '2012-01-01');
        GenreSync::sync($a->artist, ['trip hop'], 'test');
        GenreSync::sync($b->artist, ['trip hop', 'rock'], 'test');

        // The most played artist; Last.fm calls Cousin similar to them.
        $device = Device::factory()->create();
        Play::create(['device_id' => $device->id, 'track_id' => $a->tracks()->first()->id, 'played_at' => now()]);
        Enrichment::save($a->artist, 'similar_artists', json_encode(['Cousin', 'Nobody']), 'json', Enrichment::LASTFM);

        $this->get('/library')->assertOk()
            ->assertSee('Browse by Genre')->assertSee('Trip Hop')
            ->assertSee('Library at a Glance')->assertSee('1990s')->assertSee('2010s')
            ->assertSee('Similar to what you play most')->assertSee('Cousin')
            ->assertSee('By mood')->assertSee('Party')->assertSee('Sad')
            ->assertSee('By tempo')->assertSee('Slow · under 90 BPM')->assertSee('Fast · over 125')
            ->assertSee('Highest rated')->assertSee('Own files analysed');
        $this->assertNotNull($c);
    }

    public function test_the_sections_stay_away_without_enrichment(): void
    {
        $this->album('Band', 'Plain');

        $this->get('/library')->assertOk()
            ->assertDontSee('Browse by Genre')->assertDontSee('Discover')->assertDontSee('By mood')->assertSee('Library at a Glance');
    }

    public function test_the_albums_list_filters_by_genre_mood_and_tempo_and_sorts_by_rating(): void
    {
        $slow = $this->album('A', 'Slow Sad', ['rating' => '7.0'], ['bpm' => 70.0, 'mood_tags' => [['name' => 'sad', 'score' => 0.8]]]);
        $fast = $this->album('B', 'Fast Happy', ['rating' => '9.5'], ['bpm' => 160.0, 'mood_tags' => [['name' => 'happy', 'score' => 0.8]]]);
        $this->album('C', 'Unrated');
        GenreSync::sync($slow, ['jazz'], 'test');
        GenreSync::sync($fast->artist, ['pop'], 'test');

        $this->get('/albums?mood=happy')->assertOk()->assertSee('Fast Happy')->assertDontSee('Slow Sad');
        $this->get('/albums?tempo=slow')->assertOk()->assertSee('Slow Sad')->assertDontSee('Fast Happy');
        $this->get('/albums?genre=jazz')->assertOk()->assertSee('Slow Sad')->assertDontSee('Fast Happy')->assertDontSee('Unrated');
        $this->get('/albums?genre=pop')->assertOk()->assertSee('Fast Happy')->assertDontSee('Slow Sad');
        $this->get('/albums?sort=rating')->assertOk()->assertSeeInOrder(['Fast Happy', 'Slow Sad', 'Unrated']);
        $this->get('/albums?genre=nonsense')->assertOk()->assertSee('No albums here for these filters');
    }

    public function test_the_filter_selects_only_list_what_exists(): void
    {
        $this->album('A', 'Plain');
        $this->get('/albums')->assertOk()->assertDontSee('Any mood')->assertDontSee('Any tempo')->assertDontSee('All genres');

        $album = $this->album('B', 'Analysed', [], ['bpm' => 100.0, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.7]]]);
        GenreSync::sync($album->artist, ['jazz'], 'test');
        Cache::flush();

        $this->get('/albums')->assertOk()->assertSee('Any mood')->assertSee('Any tempo')->assertSee('All genres')->assertSee('Steady (90–125)');
    }

    public function test_the_album_page_has_a_sound_card_for_analysed_albums(): void
    {
        $album = $this->album('Band', 'Parachutes', [], ['bpm' => 124.0, 'key' => 'A', 'mode' => 'minor', 'danceability' => 0.8, 'mood_tags' => [['name' => 'relaxed', 'score' => 0.8]]]);

        $this->get("/albums/{$album->id}")->assertOk()
            ->assertSee('Average tempo')->assertSee('124 BPM')->assertSee('-9.0 LUFS')->assertSee('A minor')->assertSee('80%')
            ->assertSee('From 1 of 1 track analysed');

        $plain = $this->album('Band', 'Plain');
        $this->get("/albums/{$plain->id}")->assertOk()->assertDontSee('Average tempo');
    }

    public function test_album_sound_averages_tracks_and_counts_moods(): void
    {
        $album = $this->album('Band', 'LP', [], ['bpm' => 100.0, 'loudness_lufs' => -10.0, 'key' => 'C', 'mode' => 'major', 'mood_tags' => [['name' => 'happy', 'score' => 0.9], ['name' => 'party', 'score' => 0.6]]]);
        $extra = Track::create(['artist_id' => $album->artist_id, 'album_id' => $album->id, 'name' => 'Two', 'source' => 'dlna', 'external_id' => 'x:two']);
        $file = File::create(['storage_location_id' => StorageLocation::first()->id, 'path' => 'two.flac', 'hash' => 'two', 'size' => 1, 'mtime' => 1, 'status' => File::PRESENT]);
        TrackFile::create(['track_id' => $extra->id, 'file_id' => $file->id, 'role' => TrackFile::ORIGINAL, 'is_primary' => true]);
        FileAnalysis::create(['file_id' => $file->id, 'content_hash' => 'two', 'bpm' => 120.0, 'loudness_lufs' => -8.0, 'key' => 'C', 'mode' => 'major', 'mood_tags' => [['name' => 'happy', 'score' => 0.8]]]);

        $sound = AlbumSound::forAlbums([$album->id, 999])[$album->id];

        $this->assertSame(2, $sound['tracks']);
        $this->assertSame(110, $sound['bpm']);
        $this->assertSame(-8.5, $sound['loudness']);
        $this->assertSame(['happy', 'party'], $sound['moods']);
        $this->assertSame(['C major'], $sound['keys']);
        $this->assertArrayNotHasKey(999, AlbumSound::forAlbums([999]));
    }
}
