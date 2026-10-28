<?php

namespace Tests\Feature\Library;

use App\Domain\Library\GenreColors;
use App\Domain\Library\GenreSync;
use App\Domain\Library\LeadingSource;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CachesArtwork;
use Tests\TestCase;

/** `colors` of the genres in the library API: the colours most covers of a genre share. */
class GenreColorsTest extends TestCase
{
    use CachesArtwork;
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(LeadingSource::SETTING, 'all');
    }

    /** An album of the genre with a cover whose cached palette is $palette (null: cover not processed). */
    private function album(string $genre, ?array $palette, string $source = 'dlna'): Album
    {
        $n = ++$this->n;
        $url = "http://cdn.test/cover-{$n}.jpg";
        $artist = Artist::create(['name' => "Artist {$n}", 'source' => $source]);
        $album = Album::create(['artist_id' => $artist->id, 'name' => "Album {$n}", 'source' => $source, 'images' => [$url]]);
        $track = Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => "Song {$n}", 'external_id' => "{$source}:{$n}", 'source' => $source, 'duration' => 100]);
        Metadata::create([
            'metadatable_type' => Track::class, 'metadatable_id' => $track->id,
            'key' => $source === 'dlna' ? 'dlna_url' : 'external_id', 'value' => 'x', 'type' => 'url',
            'source' => $source === 'dlna' ? 'dlna:1' : 'spotify',
        ]);
        GenreSync::sync($album, [$genre], 'musicbrainz');

        if ($palette !== null) {
            $this->cacheProcessedArtwork($url, ['colors' => $palette]);
        }

        return $album;
    }

    private function colors(string $query = '', string $slug = 'rock'): array
    {
        $list = collect($this->getJson('/api/library/genres'.$query)->assertOk()->json('data'))->firstWhere('slug', $slug)['colors'];
        $this->assertSame($list, $this->getJson("/api/library/genres/{$slug}".$query)->assertOk()->json('colors'));

        return $list;
    }

    public function test_similar_blues_group_and_rank_above_a_single_red(): void
    {
        $this->album('Rock', ['#2040c0']);
        $this->album('Rock', ['#2244c4']);
        $this->album('Rock', ['#2442c2']);
        $this->album('Rock', ['#c02020']);

        $colors = $this->colors();

        $this->assertCount(2, $colors);
        $this->assertSame('#2040c0', $colors[0], 'the most frequent exact hex of the blue bucket');
        $this->assertSame('#c02020', $colors[1]);
    }

    public function test_vivid_colours_come_before_more_frequent_black_and_grey(): void
    {
        foreach (range(1, 3) as $i) {
            $this->album('Rock', ['#050505', '#808080']);
        }
        $this->album('Rock', ['#d03030']);

        $this->assertSame(['#d03030', '#050505', '#808080'], $this->colors());
    }

    public function test_only_the_first_two_palette_entries_count(): void
    {
        $this->album('Rock', ['#d03030', '#30d030', '#3030d0']);

        $this->assertSame(['#d03030', '#30d030'], $this->colors());
    }

    public function test_at_most_three_colours(): void
    {
        foreach (['#d03030', '#30d030', '#3030d0', '#d0d030', '#d030d0'] as $hex) {
            $this->album('Rock', [$hex]);
        }

        $this->assertCount(3, $this->colors());
    }

    public function test_no_processed_covers_gives_an_empty_array(): void
    {
        $this->album('Rock', null);

        $this->assertSame([], $this->colors());
        $this->assertSame([], GenreColors::dominant([null, [], ['nonsense']]));
    }

    public function test_computed_colours_are_cached_per_scope(): void
    {
        $this->album('Rock', ['#d03030']);
        $this->assertSame(['#d03030'], $this->colors());

        $this->album('Rock', ['#3030d0']);
        $this->assertSame(['#d03030'], $this->colors(), 'a new album shows after the cache TTL');

        $this->travel(GenreColors::CACHE_TTL_MINUTES + 1)->minutes();
        $this->assertCount(2, $this->colors());
    }
}
