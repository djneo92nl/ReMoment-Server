<?php

namespace Tests\Feature\Library;

use App\Domain\Backup\BackupStatus;
use App\Domain\Device\State;
use App\Domain\Library\IdAliases;
use App\Domain\Library\LibraryMerger;
use App\Domain\Library\LibraryRemover;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakeQueueEditDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Workstream 14.2: ids merged away by library:merge-duplicates keep working (docs/architecture/library-identity.md). */
class StableIdsTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeLibraryPlaybackDriver::reset();
        FakePlayerDriver::reset();
    }

    /** @return array{0: Album, 1: Album, 2: Track, 3: Track} survivor album, loser album, survivor track, loser track (already merged = false) */
    private function twins(): array
    {
        $artist = Artist::create(['name' => 'Beatles']);
        $a = Album::create(['artist_id' => $artist->id, 'name' => 'Help!', 'source' => 'dlna']);
        $b = Album::create(['artist_id' => $artist->id, 'name' => 'Help! (2)', 'source' => 'spotify']);

        return [$a, $b, $this->makeLibraryTrack($a, 'Help'), $this->makeLibraryTrack($b, 'Help 2')];
    }

    private function aliases(): array
    {
        return DB::table('id_aliases')->orderBy('entity')->orderBy('old_id')->get(['entity', 'old_id', 'new_id'])
            ->map(fn ($r) => [$r->entity, (int) $r->old_id, (int) $r->new_id])->all();
    }

    public function test_a_merge_writes_an_alias_for_every_deleted_record(): void
    {
        [$a, $b, $ta, $tb] = $this->twins();
        $artist2 = Artist::create(['name' => 'The Beatles']);
        $artistId = $a->artist_id;
        $merger = new LibraryMerger;

        $merger->mergeAlbums($a->id, [$b->id]);
        $merger->mergeTracks($ta->id, [$tb->id]);
        $merger->mergeArtists($artistId, [$artist2->id]);

        $this->assertSame([
            ['album', $b->id, $a->id],
            ['artist', $artist2->id, $artistId],
            ['track', $tb->id, $ta->id],
        ], $this->aliases());
    }

    public function test_old_album_artist_and_track_ids_answer_with_the_survivor_and_canonical_id(): void
    {
        [$a, $b, $ta, $tb] = $this->twins();
        $artist2 = Artist::create(['name' => 'The Beatles']);
        $merger = new LibraryMerger;
        $merger->mergeAlbums($a->id, [$b->id]);
        $merger->mergeArtists($a->artist_id, [$artist2->id]);
        $merger->mergeTracks($ta->id, [$tb->id]);

        $this->getJson("/api/library/albums/{$b->id}")->assertOk()->assertJsonPath('id', $a->id)->assertJsonPath('canonical_id', $a->id);
        $this->getJson("/api/library/albums/{$a->id}")->assertOk()->assertJsonPath('canonical_id', $a->id);
        $this->getJson("/api/library/artists/{$artist2->id}")->assertOk()->assertJsonPath('canonical_id', $a->artist_id);
        $this->putJson("/api/library/tracks/{$tb->id}/favorite", ['favorite' => true])->assertOk()->assertJsonPath('canonical_id', $ta->id);
        $this->assertNotNull($ta->fresh()->favorited_at);
    }

    public function test_favorite_with_an_old_id_favorites_the_survivor(): void
    {
        [$a, $b] = $this->twins();
        (new LibraryMerger)->mergeAlbums($a->id, [$b->id]);

        $this->putJson("/api/library/albums/{$b->id}/favorite", ['favorite' => true])->assertOk()->assertJsonPath('canonical_id', $a->id);

        $this->assertNotNull($a->fresh()->favorited_at);
    }

    public function test_play_bodies_with_old_ids_play_the_survivor(): void
    {
        [$a, $b, $ta, $tb] = $this->twins();
        $device = Device::factory()->inState(State::Playing)->create(['device_driver' => FakeLibraryPlaybackDriver::class]);
        $merger = new LibraryMerger;
        $merger->mergeTracks($ta->id, [$tb->id]);
        $merger->mergeAlbums($a->id, [$b->id]);

        $this->postJson("/api/devices/{$device->id}/library/play", ['track_id' => $tb->id])->assertOk();
        $this->assertSame([$ta->id], FakeLibraryPlaybackDriver::$calls[0]['tracks']->pluck('id')->all());

        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => $b->id, 'start_track_id' => $tb->id])->assertOk();
        $this->postJson("/api/devices/{$device->id}/library/play-album", ['album_id' => 99999])->assertStatus(422);
    }

    public function test_the_queue_add_accepts_an_old_id(): void
    {
        [$a, $b, $ta, $tb] = $this->twins();
        $device = Device::factory()->driver(FakeQueueEditDriver::class)->inState(State::Playing)->create();
        (new LibraryMerger)->mergeTracks($ta->id, [$tb->id]);

        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $tb->id])->assertOk();

        $this->assertSame([['addToQueue', [$ta->id]]], FakePlayerDriver::$calls);
        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => 99999])->assertStatus(422);
    }

    public function test_a_merge_chain_resolves_in_one_hop(): void
    {
        $artist = Artist::create(['name' => 'X']);
        [$one, $two, $three] = collect(['One', 'Two', 'Three'])
            ->map(fn ($n) => Album::create(['artist_id' => $artist->id, 'name' => $n]))->all();
        $merger = new LibraryMerger;

        $merger->mergeAlbums($two->id, [$one->id]);   // A into B
        $merger->mergeAlbums($three->id, [$two->id]); // B into C

        $this->assertSame([
            ['album', $one->id, $three->id],
            ['album', $two->id, $three->id],
        ], $this->aliases());
        $this->getJson("/api/library/albums/{$one->id}")->assertOk()->assertJsonPath('canonical_id', $three->id);
        $this->getJson("/api/library/albums/{$two->id}")->assertOk()->assertJsonPath('canonical_id', $three->id);
    }

    public function test_no_alias_ever_points_at_an_alias(): void
    {
        $ids = app(IdAliases::class);
        $ids->record('track', 1, 2);
        $ids->record('track', 3, 1); // 1 is already an alias: 3 goes to 2

        $this->assertSame([['track', 1, 2], ['track', 3, 2]], $this->aliases());
    }

    public function test_unknown_ids_stay_404_and_a_removed_survivor_makes_its_aliases_go(): void
    {
        $this->getJson('/api/library/albums/99999')->assertNotFound();
        $this->getJson('/api/library/artists/abc')->assertNotFound();

        [$a, $b, $ta, $tb] = $this->twins();
        (new LibraryMerger)->mergeTracks($ta->id, [$tb->id]);
        LibraryRemover::track($ta, force: true);

        $this->assertSame([], array_filter($this->aliases(), fn ($r) => $r[0] === 'track'));
        $this->putJson("/api/library/tracks/{$tb->id}/favorite", ['favorite' => true])->assertNotFound();
    }

    public function test_a_delete_never_follows_an_alias(): void
    {
        [$a, $b] = $this->twins();
        (new LibraryMerger)->mergeAlbums($a->id, [$b->id]);
        $this->actingAs(\App\Models\User::factory()->create());

        $this->delete("/albums/{$b->id}")->assertNotFound();
        $this->assertNotNull(Album::find($a->id));
    }

    public function test_web_pages_redirect_permanently_to_the_canonical_url(): void
    {
        [$a, $b] = $this->twins();
        $artist2 = Artist::create(['name' => 'The Beatles']);
        $merger = new LibraryMerger;
        $merger->mergeAlbums($a->id, [$b->id]);
        $merger->mergeArtists($a->artist_id, [$artist2->id]);

        $this->get("/albums/{$b->id}")->assertStatus(301)->assertRedirect("/albums/{$a->id}");
        $this->get("/artists/{$artist2->id}?source=files")->assertStatus(301)->assertRedirect("/artists/{$a->artist_id}?source=files");
        $this->get('/albums/99999')->assertNotFound();
    }

    public function test_a_failing_merge_rolls_the_aliases_back(): void
    {
        [$a, $b, $ta, $tb] = $this->twins();
        // The survivor's final save runs after the alias row is written: failing there must undo the alias and the delete.
        Track::saved(function (Track $saved) use ($ta) {
            if ($saved->id === $ta->id) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            (new LibraryMerger)->mergeTracks($ta->id, [$tb->id]);
            $this->fail('the merge should have thrown');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->aliases());
        $this->assertNotNull(Track::find($tb->id));
    }

    public function test_aliases_survive_a_backup_round_trip(): void
    {
        // Rows only in id_aliases: PRAGMA foreign_keys cannot be switched off inside the test transaction, so a library
        // with foreign keys could not be restored here (the restore itself does it outside tests).
        app(IdAliases::class)->record('album', 41, 42);
        $dir = sys_get_temp_dir().'/remoment-aliases-'.uniqid();
        config(['backup.path' => $dir]);

        $this->assertSame(0, Artisan::call('backup:run'));
        $archive = BackupStatus::archives($dir);
        $archive = end($archive);

        DB::table('id_aliases')->delete();
        $this->artisan('backup:restore', ['archive' => $archive, '--force' => true])->assertSuccessful();

        $this->assertSame([['album', 41, 42]], $this->aliases());
        array_map('unlink', glob($dir.'/*'));
        @rmdir($dir);
    }

    public function test_playlists_resolve_too_even_though_nothing_merges_them_yet(): void
    {
        $playlist = Playlist::create(['name' => 'Mix', 'source' => 'local']);
        app(IdAliases::class)->record('playlist', 4242, $playlist->id);

        $this->getJson('/api/library/playlists/4242')->assertOk()->assertJsonPath('canonical_id', $playlist->id);
    }
}
