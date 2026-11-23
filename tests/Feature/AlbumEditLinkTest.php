<?php

namespace Tests\Feature;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlbumEditLinkTest extends TestCase
{
    use RefreshDatabase;

    private function album(): Album
    {
        $artist = Artist::create(['name' => 'Band']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => 'LP']);
        Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Song']);

        return $album;
    }

    public function test_guest_sees_no_edit_entry_or_diagnosis(): void
    {
        $album = $this->album();

        $this->get(route('albums.show', $album))->assertOk()->assertDontSee('album-edit-tags')->assertDontSee('album-no-artwork');
    }

    public function test_admin_sees_edit_link_on_the_album_page(): void
    {
        $album = $this->album();

        $this->actingAs(User::factory()->create())->get(route('albums.show', $album))
            ->assertOk()
            ->assertSee('album-edit-tags', false)
            ->assertDontSee('album-no-artwork', false)
            ->assertSee(route('settings.storage.album', $album), false);
    }

    public function test_the_reason_for_no_cover_is_on_the_edit_page(): void
    {
        $album = $this->album();

        $this->actingAs(User::factory()->create())->get(route('settings.storage.album', $album))
            ->assertOk()->assertSee('album-no-artwork', false)->assertSee('Why this album has no cover');
    }
}
