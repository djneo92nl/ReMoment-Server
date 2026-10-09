<?php

namespace Tests\Feature\Library;

use App\Domain\Library\GenreSync;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Livewire\SmartPlaylistEditor;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Smart playlists: rules to tracks, refreshing, the editor and the pages around it. */
class SmartPlaylistTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(LeadingSource::SETTING, 'all');
        $this->artist = Artist::create(['name' => 'Portishead', 'source' => 'dlna']);
    }

    private function track(string $name, ?string $released = null, ?Artist $artist = null, bool $dlna = true, ?string $spotifyId = null): Track
    {
        $artist ??= $this->artist;
        $album = Album::create(['artist_id' => $artist->id, 'name' => "LP {$name}", 'source' => 'dlna', 'released_at' => $released]);

        return $this->makeLibraryTrack($album, $name, $dlna, $spotifyId, $artist->id);
    }

    private function meta(Track|Album|Artist $model, string $key, string $value): void
    {
        Metadata::create(['metadatable_type' => $model::class, 'metadatable_id' => $model->id, 'key' => $key, 'value' => $value, 'type' => 'string', 'source' => 'test']);
    }

    private function play(Track $track, ?\DateTimeInterface $at = null, bool $skipped = false): void
    {
        Play::create(['device_id' => Device::factory()->create()->id, 'track_id' => $track->id, 'played_at' => $at ?? now(), 'skipped' => $skipped]);
    }

    /** @return list<string> */
    private function names(array $rules, string $match = 'all', array $extra = []): array
    {
        return SmartPlaylistBuilder::tracks(['match' => $match, 'rules' => $rules, 'sort' => 'name'] + $extra)->pluck('tracks.name')->all();
    }

    public function test_genre_matches_the_track_its_album_or_its_artist(): void
    {
        $a = $this->track('A');
        $b = $this->track('B');
        $c = $this->track('C', artist: Artist::create(['name' => 'Other', 'source' => 'dlna']));
        $d = $this->track('D');

        GenreSync::sync($a, ['trip hop'], 'test');
        GenreSync::sync($b->album, ['trip hop'], 'test');
        GenreSync::sync($c->artist, ['trip hop'], 'test');
        GenreSync::sync($d, ['rock'], 'test');
        $genre = \App\Models\Media\Genre::where('name', 'Trip Hop')->first();

        $this->assertSame(['A', 'B', 'C'], $this->names([['field' => 'genre', 'operator' => 'is', 'value' => $genre->id]]));
        $this->assertSame(['D'], $this->names([['field' => 'genre', 'operator' => 'is_not', 'value' => $genre->id]]));
    }

    public function test_decade_and_year_use_the_album_release_date(): void
    {
        $this->track('Old', '1994-08-22');
        $this->track('Mid', '1999-12-31');
        $this->track('New', '2008-04-29');
        $this->track('Undated');

        $this->assertSame(['Mid', 'Old'], $this->names([['field' => 'decade', 'operator' => 'is', 'value' => '1990']]));
        $this->assertSame(['New', 'Undated'], $this->names([['field' => 'decade', 'operator' => 'is_not', 'value' => '1990']]));
        $this->assertSame(['New'], $this->names([['field' => 'year', 'operator' => 'gte', 'value' => 2000]]));
        $this->assertSame(['Mid', 'Old'], $this->names([['field' => 'year', 'operator' => 'lte', 'value' => 1999]]));
        $this->assertSame(['Mid'], $this->names([['field' => 'year', 'operator' => 'eq', 'value' => 1999]]));
    }

    public function test_text_rules_search_artist_and_title(): void
    {
        $this->track('Roads');
        $this->track('Glory Box', artist: Artist::create(['name' => 'Massive Attack', 'source' => 'dlna']));

        $this->assertSame(['Roads'], $this->names([['field' => 'artist', 'operator' => 'contains', 'value' => 'portis']]));
        $this->assertSame(['Roads'], $this->names([['field' => 'artist', 'operator' => 'not_contains', 'value' => 'massive']]));
        $this->assertSame(['Glory Box'], $this->names([['field' => 'title', 'operator' => 'contains', 'value' => 'glory']]));
    }

    public function test_play_history_rules(): void
    {
        $never = $this->track('Never');
        $old = $this->track('Old');
        $fresh = $this->track('Fresh');
        $skipped = $this->track('Skipped');

        $this->play($old, now()->subDays(200));
        $this->play($fresh, now()->subDay());
        $this->play($fresh, now()->subHours(2));
        $this->play($skipped, now()->subDays(3), skipped: true);

        $this->assertSame(['Never'], $this->names([['field' => 'plays', 'operator' => 'eq', 'value' => 0]]));
        $this->assertSame(['Fresh'], $this->names([['field' => 'plays', 'operator' => 'gte', 'value' => 2]]));
        $this->assertSame(['Skipped'], $this->names([['field' => 'skips', 'operator' => 'gte', 'value' => 1]]));
        $this->assertSame(['Fresh', 'Skipped'], $this->names([['field' => 'last_played', 'operator' => 'within', 'value' => 7]]));
        $this->assertSame(['Never', 'Old'], $this->names([['field' => 'last_played', 'operator' => 'not_within', 'value' => 30]]), 'never played counts as not played lately');
    }

    public function test_favorite_lossless_explicit_source_and_length(): void
    {
        $fav = $this->track('Fav');
        $fav->album->update(['favorited_at' => now()]);
        $flac = $this->track('Flac');
        $this->meta($flac, 'mime_type', 'audio/flac');
        $this->meta($flac, 'explicit', '1');
        $spotify = $this->track('Streamed', dlna: false, spotifyId: 'abc');
        $spotify->update(['duration' => 600]);

        $this->assertSame(['Fav'], $this->names([['field' => 'favorite', 'operator' => 'yes', 'value' => '']]));
        $this->assertSame(['Flac', 'Streamed'], $this->names([['field' => 'favorite', 'operator' => 'no', 'value' => '']]));
        $this->assertSame(['Flac'], $this->names([['field' => 'lossless', 'operator' => 'yes', 'value' => '']]));
        $this->assertSame(['Flac'], $this->names([['field' => 'explicit', 'operator' => 'yes', 'value' => '']]));
        $this->assertSame(['Streamed'], $this->names([['field' => 'source', 'operator' => 'is', 'value' => 'spotify']]));
        $this->assertSame(['Streamed'], $this->names([['field' => 'duration', 'operator' => 'gte', 'value' => 5]]));
    }

    public function test_metadata_rules_look_at_the_album_and_artist(): void
    {
        $a = $this->track('A');
        $b = $this->track('B');
        $this->meta($a->album, 'label', 'Go! Discs');
        $this->meta($a->album, 'release_type', 'album');
        $this->meta($a->artist, 'country', 'GB');
        $this->meta($b->album, 'tags', '["jazz","chill"]');

        $this->assertSame(['A', 'B'], $this->names([['field' => 'country', 'operator' => 'is', 'value' => 'gb']]), 'both tracks share the artist');
        $this->assertSame(['A'], $this->names([['field' => 'label', 'operator' => 'contains', 'value' => 'discs']]));
        $this->assertSame(['A'], $this->names([['field' => 'release_type', 'operator' => 'is', 'value' => 'album']]));
        $this->assertSame(['B'], $this->names([['field' => 'tag', 'operator' => 'contains', 'value' => 'chill']]));
    }

    public function test_match_all_and_match_any(): void
    {
        $this->track('Old', '1994-01-01');
        $this->track('New', '2008-01-01');
        $this->track('Other', '2008-01-01', Artist::create(['name' => 'Other', 'source' => 'dlna']));
        $rules = [
            ['field' => 'decade', 'operator' => 'is', 'value' => '2000'],
            ['field' => 'artist', 'operator' => 'contains', 'value' => 'portis'],
        ];

        $this->assertSame(['New'], $this->names($rules, 'all'));
        $this->assertSame(['New', 'Old', 'Other'], $this->names($rules, 'any'));
        $this->assertSame(3, SmartPlaylistBuilder::count(['rules' => []]));
    }

    public function test_radio_stubs_and_hidden_sources_are_left_out(): void
    {
        $this->track('Kept');
        $this->track('Stub')->update(['source' => 'radio']);
        $this->track('Streamed', dlna: false, spotifyId: 'abc');

        $this->assertSame(['Kept', 'Streamed'], $this->names([]));

        Setting::set(LeadingSource::SETTING, 'dlna');
        $this->assertSame(['Kept'], $this->names([]));
    }

    public function test_normalize_drops_unknown_incomplete_and_out_of_range_rules(): void
    {
        $definition = SmartPlaylistBuilder::normalize([
            'match' => 'bogus', 'sort' => 'nope', 'limit' => 99999,
            'rules' => [
                ['field' => 'nope', 'operator' => 'is', 'value' => 'x'],
                ['field' => 'year', 'operator' => 'contains', 'value' => 1999],
                ['field' => 'year', 'operator' => 'eq', 'value' => ''],
                ['field' => 'year', 'operator' => 'eq', 'value' => '1999'],
                ['field' => 'lossless', 'operator' => 'yes'],
                ['field' => 'source', 'operator' => 'is', 'value' => 'tape'],
            ],
        ]);

        $this->assertSame('all', $definition['match']);
        $this->assertSame('random', $definition['sort']);
        $this->assertSame(SmartPlaylistBuilder::MAX_LIMIT, $definition['limit']);
        $this->assertSame([
            ['field' => 'year', 'operator' => 'eq', 'value' => 1999],
            ['field' => 'lossless', 'operator' => 'yes', 'value' => ''],
        ], $definition['rules']);
    }

    public function test_sorting_and_limit(): void
    {
        $a = $this->track('A', '2001-01-01');
        $b = $this->track('B', '1990-01-01');
        $c = $this->track('C');
        $this->play($b);
        $this->play($b);
        $this->play($a);

        $ids = fn (string $sort, int $limit = 10) => SmartPlaylistBuilder::tracks(['sort' => $sort, 'limit' => $limit])->pluck('tracks.name')->all();

        $this->assertSame(['B', 'A', 'C'], $ids('most_played'));
        $this->assertSame(['C', 'A', 'B'], $ids('least_played'));
        $this->assertSame(['A', 'B', 'C'], $ids('newest'));
        $this->assertSame(['B', 'A', 'C'], $ids('oldest'), 'undated last');
        $this->assertSame(['C', 'B', 'A'], $ids('recently_added'));
        $this->assertSame(['A', 'B'], $ids('name', 2));
        $this->assertCount(3, $ids('random'));
    }

    public function test_refresh_replaces_the_tracks_in_order_and_is_repeatable(): void
    {
        $a = $this->track('A', '2001-01-01');
        $b = $this->track('B', '1990-01-01');
        $playlist = Playlist::create(['name' => 'Smart', 'source' => 'smart', 'rules' => [
            'match' => 'all', 'rules' => [['field' => 'year', 'operator' => 'gte', 'value' => 1980]], 'sort' => 'oldest', 'limit' => 50,
        ]]);

        $this->assertSame(2, SmartPlaylistBuilder::refresh($playlist));
        $this->assertSame([$b->id, $a->id], $playlist->tracks()->pluck('tracks.id')->all());
        $this->assertNotNull($playlist->fresh()->refreshed_at);

        $c = $this->track('C', '1985-01-01');
        SmartPlaylistBuilder::refresh($playlist);
        SmartPlaylistBuilder::refresh($playlist);

        $this->assertSame([$c->id, $b->id, $a->id], $playlist->tracks()->pluck('tracks.id')->all());
        $this->assertSame([0, 1, 2], $playlist->tracks()->pluck('playlist_track.position')->all());
    }

    public function test_refresh_command_only_touches_smart_playlists(): void
    {
        $this->track('A');
        $smart = Playlist::create(['name' => 'Smart', 'source' => 'smart', 'rules' => SmartPlaylistBuilder::defaults()]);
        $local = Playlist::create(['name' => 'Mine', 'source' => 'local']);

        $this->artisan('playlists:refresh-smart')->assertSuccessful();

        $this->assertSame(1, $smart->tracks()->count());
        $this->assertSame(0, $local->tracks()->count());
    }

    public function test_creating_a_smart_playlist_fills_it_and_shows_the_editor(): void
    {
        $this->track('A');

        $response = $this->post(route('playlists.store'), ['name' => 'Everything', 'smart' => '1']);

        $playlist = Playlist::where('name', 'Everything')->firstOrFail();
        $response->assertRedirect(route('playlists.show', $playlist));
        $this->assertSame('smart', $playlist->source);
        $this->assertSame(1, $playlist->tracks()->count());

        $this->get(route('playlists.show', $playlist))->assertOk()->assertSee('Smart playlist')->assertSeeLivewire(SmartPlaylistEditor::class);
        $this->get(route('playlists.index'))->assertOk()->assertSee('Everything');
    }

    public function test_a_plain_playlist_stays_local(): void
    {
        $this->post(route('playlists.store'), ['name' => 'Mine']);

        $playlist = Playlist::where('name', 'Mine')->firstOrFail();
        $this->assertSame('local', $playlist->source);
        $this->assertNull($playlist->rules);
    }

    public function test_the_editor_previews_saves_and_refreshes(): void
    {
        $this->track('Old', '1994-01-01');
        $this->track('New', '2008-01-01');
        $playlist = Playlist::create(['name' => 'Smart', 'source' => 'smart', 'rules' => SmartPlaylistBuilder::defaults()]);
        SmartPlaylistBuilder::refresh($playlist);

        Livewire::test(SmartPlaylistEditor::class, ['playlist' => $playlist])
            ->assertSee('2 tracks match')
            ->call('addRule')
            ->set('rules.0.field', 'decade')
            ->assertSet('rules.0.operator', 'is')
            ->assertSee('1 rule without a value is ignored')
            ->set('rules.0.value', '1990')
            ->assertSee('1 track matches')
            ->set('name', 'Nineties')
            ->call('save')
            ->assertRedirect(route('playlists.show', $playlist));

        $playlist->refresh();
        $this->assertSame('Nineties', $playlist->name);
        $this->assertSame([['field' => 'decade', 'operator' => 'is', 'value' => '1990']], $playlist->rules['rules']);
        $this->assertSame(['Old'], $playlist->tracks()->pluck('tracks.name')->all());
    }

    public function test_a_changed_field_resets_the_row_and_rows_can_be_removed(): void
    {
        $playlist = Playlist::create(['name' => 'Smart', 'source' => 'smart', 'rules' => SmartPlaylistBuilder::defaults()]);

        Livewire::test(SmartPlaylistEditor::class, ['playlist' => $playlist])
            ->call('addRule')
            ->set('rules.0.field', 'plays')
            ->set('rules.0.operator', 'gte')
            ->set('rules.0.value', '3')
            ->set('rules.0.field', 'lossless')
            ->assertSet('rules.0.operator', 'yes')
            ->assertSet('rules.0.value', '')
            ->call('removeRule', 0)
            ->assertSet('rules', []);
    }

    public function test_smart_playlists_can_be_deleted_but_spotify_ones_cannot(): void
    {
        $smart = Playlist::create(['name' => 'Smart', 'source' => 'smart', 'rules' => SmartPlaylistBuilder::defaults()]);
        $spotify = Playlist::create(['name' => 'Synced', 'source' => 'spotify', 'external_id' => 'spotify:playlist:1']);

        $this->delete(route('playlists.destroy', $spotify))->assertSessionHas('error');
        $this->assertModelExists($spotify);

        $this->delete(route('playlists.destroy', $smart))->assertRedirect(route('playlists.index'));
        $this->assertModelMissing($smart);
    }
}
