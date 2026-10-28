<?php

namespace Tests\Feature;

use App\Domain\Library\IdAliases;
use App\Domain\Library\LibraryMerger;
use App\Models\Media\Album;
use App\Models\Media\AlbumAvailability;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\CatalogueArtist;
use App\Models\Media\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** library:merge-duplicates keeps wishes, links and lookup answers, and retired catalogue ids still resolve (workstream 3 with 14.2). */
class CatalogueMergeTest extends TestCase
{
    use RefreshDatabase;

    private function row(Artist $artist, string $mbid, array $extra = []): CatalogueAlbum
    {
        return CatalogueAlbum::create($extra + ['artist_id' => $artist->id, 'release_group_mbid' => $mbid, 'title' => 'LP '.$mbid, 'type' => 'album', 'seen_at' => now()]);
    }

    private function answer(array $owner, string $provider, string $checked): AlbumAvailability
    {
        return AlbumAvailability::create($owner + ['provider' => $provider, 'status' => 'available', 'external_id' => $provider.'-'.$checked, 'confidence' => 90, 'match' => 'search', 'checked_at' => $checked]);
    }

    public function test_merging_albums_moves_the_link_the_answers_and_ownership(): void
    {
        $artist = Artist::create(['name' => 'Band']);
        $streamed = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'spotify']);
        $files = Album::create(['artist_id' => $artist->id, 'name' => 'LP', 'source' => 'files']);
        Track::create(['album_id' => $files->id, 'artist_id' => $artist->id, 'name' => 'T', 'source' => 'files', 'external_id' => 'local:file:1']);
        $row = $this->row($artist, 'rg-1', ['album_id' => $streamed->id, 'wanted_at' => now()]);
        $this->answer(['album_id' => $streamed->id], 'spotify', '2026-01-01');
        $this->answer(['album_id' => $files->id], 'spotify', '2026-06-01');
        $this->answer(['album_id' => $streamed->id], 'deezer', '2026-02-01');

        app(LibraryMerger::class)->mergeAlbums($streamed->id, [$files->id]);

        $row->refresh();
        $this->assertSame($streamed->id, $row->album_id, 'the link survives the deletion of the loser');
        $this->assertNotNull($row->fulfilled_at, 'the files that came with the merge make it owned');
        $this->assertSame(['deezer', 'spotify'], AlbumAvailability::where('album_id', $streamed->id)->orderBy('provider')->pluck('provider')->all());
        $this->assertSame('spotify-2026-06-01', AlbumAvailability::where('album_id', $streamed->id)->where('provider', 'spotify')->value('external_id'), 'the newer answer wins');
    }

    public function test_merging_artists_folds_equal_release_groups_and_keeps_wishes(): void
    {
        $keep = Artist::create(['name' => 'Band', 'source' => 'files']);
        $lose = Artist::create(['name' => 'Band', 'source' => 'spotify']);
        $keepSame = $this->row($keep, 'rg-same', ['ignored_at' => now()->subDay()]);
        $loseSame = $this->row($lose, 'rg-same', ['wanted_at' => now()]);
        $loseOnly = $this->row($lose, 'rg-only');
        $this->answer(['catalogue_album_id' => $loseSame->id], 'spotify', '2026-03-01');
        CatalogueArtist::create(['artist_id' => $lose->id, 'status' => 'ok']);
        CatalogueArtist::create(['artist_id' => $keep->id, 'status' => 'ok', 'next_sync_at' => now()->addDays(20)]);

        app(LibraryMerger::class)->mergeArtists($keep->id, [$lose->id]);

        $this->assertSame(2, CatalogueAlbum::where('artist_id', $keep->id)->count());
        $keepSame->refresh();
        $this->assertNotNull($keepSame->wanted_at, 'the wish beats "not interested"');
        $this->assertNull($keepSame->ignored_at);
        $this->assertSame($keep->id, $loseOnly->fresh()->artist_id);
        $this->assertNull(CatalogueAlbum::find($loseSame->id));
        $this->assertSame($keepSame->id, app(IdAliases::class)->target('catalogue_album', $loseSame->id));
        $this->assertSame(1, AlbumAvailability::where('catalogue_album_id', $keepSame->id)->count());
        $this->assertNull(CatalogueArtist::find($lose->id));
        $this->assertTrue(CatalogueArtist::find($keep->id)->next_sync_at->lte(now()->addMinute()), 'synced again soon');
    }

    public function test_a_retired_catalogue_id_still_works_and_names_the_canonical_id(): void
    {
        $keep = Artist::create(['name' => 'Band', 'source' => 'files']);
        $lose = Artist::create(['name' => 'Band', 'source' => 'spotify']);
        $keepSame = $this->row($keep, 'rg-same');
        $loseSame = $this->row($lose, 'rg-same');

        app(LibraryMerger::class)->mergeArtists($keep->id, [$lose->id]);

        $this->getJson("/api/library/catalogue/{$loseSame->id}")->assertOk()->assertJson(['id' => $keepSame->id, 'canonical_id' => $keepSame->id]);
        $this->putJson("/api/library/catalogue/{$loseSame->id}/wanted", ['wanted' => true])->assertOk()->assertJson(['canonical_id' => $keepSame->id]);
        $this->assertNotNull($keepSame->fresh()->wanted_at);
        $this->getJson('/api/library/catalogue/987654')->assertNotFound();
    }
}
