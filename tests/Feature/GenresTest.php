<?php

namespace Tests\Feature;

use App\Domain\Library\Enrichment;
use App\Domain\Library\GenreNormalizer;
use App\Domain\Library\GenreSync;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Genre;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Canonical genres: normalizing, syncing from every source, the web pages and the API. */
class GenresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
    }

    public function test_spellings_collapse_and_non_genres_are_dropped(): void
    {
        $names = array_column(GenreNormalizer::normalize([
            'hip hop', 'Hip-Hop', 'hiphop', 'rnb', 'R&B', 'drum and bass', 'dnb', 'post punk', 'uk garage',
            'seen live', 'american', '80s', 'Rock', '', 'x', 42,
        ]), 1);

        $this->assertSame(['Hip Hop', 'R&B', 'Drum & Bass', 'Post Punk', 'UK Garage', 'Rock'], $names);
    }

    public function test_aliases_decide_the_display_name(): void
    {
        $this->assertSame(['Drum & Bass'], array_column(GenreNormalizer::normalize(['dnb', 'drum n bass']), 1));
        $this->assertSame(['Hip-Hop'], array_column(GenreNormalizer::normalize(['hip hop/rap']), 1));
    }

    public function test_sources_share_one_genre_and_keep_the_best_position(): void
    {
        $a = Artist::create(['name' => 'A', 'source' => 'x']);
        $b = Artist::create(['name' => 'B', 'source' => 'x']);

        GenreSync::sync($a, ['zydeco', 'pop', 'Post-Punk'], 'spotify');
        GenreSync::sync($a, ['post punk'], 'musicbrainz');
        GenreSync::sync($b, ['Post Punk'], 'musicbrainz');

        $this->assertSame(1, Genre::where('name', 'like', 'Post%')->count());
        $this->assertSame(['Post-Punk', 'Zydeco', 'Pop'], $a->fresh()->genres(), 'moved up to the best position (ties by name)');
        $this->assertSame(2, Genre::where('name_key', 'postpunk')->first()->artists()->count());
    }

    public function test_enrichment_save_syncs_genres_of_any_record(): void
    {
        $artist = Artist::create(['name' => 'A', 'source' => 'x']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'x']);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'T', 'source' => 'x']);

        Enrichment::save($artist, 'genres', json_encode(['rock']), 'json', 'musicbrainz');
        Enrichment::save($album, 'genres', json_encode(['shoegaze']), 'json', 'musicbrainz');
        Enrichment::save($track, 'genres', json_encode(['Rock', 'Live']), 'json', 'dlna:1');

        $this->assertSame(['Rock'], $artist->genres());
        $this->assertSame(['Shoegaze'], $album->genres());
        $this->assertSame(['Rock', 'Live'], $track->genres());
    }

    public function test_sync_command_rebuilds_from_stored_metadata(): void
    {
        $artist = Artist::create(['name' => 'A', 'source' => 'x']);
        \App\Models\Media\Metadata::create([
            'metadatable_type' => Artist::class, 'metadatable_id' => $artist->id,
            'key' => 'genres', 'value' => json_encode(['jazz', 'hip hop']), 'type' => 'json', 'source' => 'musicbrainz',
        ]);

        $this->artisan('library:sync-genres')->assertSuccessful();

        $this->assertSame(['Jazz', 'Hip Hop'], $artist->genres());
    }

    public function test_genre_pages_use_slugs_and_redirect_old_names(): void
    {
        $artist = Artist::create(['name' => 'Rapper', 'source' => 'x']);
        Album::create(['artist_id' => $artist->id, 'name' => 'Beats', 'source' => 'x']);
        GenreSync::sync($artist, ['hip hop'], 'musicbrainz');
        $genre = Genre::firstOrFail();

        $this->get('/genres')->assertOk()->assertSee('Hip Hop');
        $this->get("/genres/{$genre->slug}")->assertOk()->assertSee('Rapper');
        $this->get('/genres/Hip-Hop')->assertRedirect("/genres/{$genre->slug}");
        $this->get('/genres/nothing-here')->assertNotFound();
    }

    public function test_library_api_lists_genres_and_their_items(): void
    {
        $artist = Artist::create(['name' => 'Muse', 'source' => 'x']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'Absolution', 'source' => 'x', 'released_at' => '2003-09-15']);
        Artist::create(['name' => 'Nobody', 'source' => 'x']);
        GenreSync::sync($artist, ['alternative rock'], 'musicbrainz');
        GenreSync::sync($album, ['alternative rock'], 'musicbrainz');

        $this->getJson('/api/library/genres')->assertOk()->assertExactJson(['data' => [
            ['slug' => 'alternative-rock', 'name' => 'Alternative Rock', 'artist_count' => 1, 'album_count' => 1],
        ]]);

        $this->getJson('/api/library/genres/alternative-rock')->assertOk()
            ->assertJsonPath('name', 'Alternative Rock')
            ->assertJsonPath('artists.0.name', 'Muse')
            ->assertJsonPath('albums.0.name', 'Absolution')
            ->assertJsonPath('albums.0.year', 2003);

        $this->getJson("/api/library/artists/{$artist->id}")->assertJsonPath('genres', ['Alternative Rock'])->assertJsonPath('details.bio', null);
        $this->getJson("/api/library/albums/{$album->id}")->assertJsonPath('genres', ['Alternative Rock']);
        $this->getJson('/api/library/genres/unknown')->assertNotFound();
    }
}
