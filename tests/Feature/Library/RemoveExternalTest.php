<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LibraryRemover;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class RemoveExternalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(\App\Domain\Library\LeadingSource::SETTING, 'all');
    }

    private function spotifyAlbum(string $artistName = 'Muse', string $albumName = 'Absolution'): Album
    {
        $artist = Artist::firstOrCreate(['name' => $artistName], ['source' => 'spotify']);
        $album = Album::create(['artist_id' => $artist->id, 'name' => $albumName, 'source' => 'spotify', 'images' => [['url' => 'https://img.test/c.jpg']]]);
        Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Hysteria', 'external_id' => 'spotify:track:1', 'source' => 'spotify', 'duration' => 200]);
        Track::create(['artist_id' => $artist->id, 'album_id' => $album->id, 'name' => 'Stockholm', 'external_id' => 'spotify:track:2', 'source' => 'spotify', 'duration' => 180]);

        return $album;
    }

    private function dlnaTrackOn(Album $album): Track
    {
        $track = Track::create(['artist_id' => $album->artist_id, 'album_id' => $album->id, 'name' => 'Local', 'external_id' => '1:1', 'source' => 'dlna', 'duration' => 100]);
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => 'dlna_url', 'value' => 'http://x', 'type' => 'url', 'source' => 'dlna:1']);

        return $track;
    }

    private function play(Track $track): Play
    {
        $device = Device::firstOrCreate(['ip_address' => '10.0.0.1'], [
            'device_name' => 'Speaker', 'device_brand_name' => 'Test', 'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class, 'device_driver_name' => 'Fake',
        ]);

        return Play::create(['device_id' => $device->id, 'track_id' => $track->id, 'source_type' => 'spotify', 'played_at' => now()]);
    }

    public function test_removing_an_external_album_keeps_its_plays_as_text_and_cleans_up(): void
    {
        $album = $this->spotifyAlbum();
        $play = $this->play($album->tracks()->first());

        $this->assertSame(2, LibraryRemover::album($album));

        $this->assertSame(0, Track::count() + Album::count() + Artist::count());
        $play->refresh();
        $this->assertNull($play->track_id);
        $this->assertSame('Hysteria', $play->track_name);
        $this->assertSame('Muse', $play->artist_name);
        $this->assertSame('Absolution', $play->album_name);
        $this->assertSame('https://img.test/c.jpg', $play->image_url);
    }

    public function test_an_album_with_dlna_tracks_is_not_removed(): void
    {
        $album = $this->spotifyAlbum();
        $this->dlnaTrackOn($album);

        $this->assertNull(LibraryRemover::album($album));
        $this->assertSame(3, Track::count());
    }

    public function test_a_single_external_track_can_go_but_a_dlna_one_cannot(): void
    {
        $album = $this->spotifyAlbum();
        $local = $this->dlnaTrackOn($album);
        $spotify = $album->tracks()->where('name', 'Hysteria')->first();

        $this->assertFalse(LibraryRemover::track($local));
        $this->assertTrue(LibraryRemover::track($spotify));
        $this->assertSame(2, Track::count());
        $this->assertNotNull(Album::find($album->id));
    }

    public function test_only_the_admin_can_remove_and_the_page_offers_it(): void
    {
        $album = $this->spotifyAlbum();

        $this->delete(route('albums.destroy', $album))->assertRedirect('/login');
        $this->assertSame(1, Album::count());

        $this->get(route('albums.show', $album))->assertDontSee('Remove from library');

        $this->actingAs(User::factory()->create());
        $this->get(route('albums.show', $album))->assertSee('Remove from library')->assertSee('Spotify');
        $this->delete(route('albums.destroy', $album))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, Album::count());
    }

    public function test_album_cards_show_where_an_album_comes_from(): void
    {
        $this->dlnaTrackOn($this->spotifyAlbum());

        $this->get('/albums')->assertSee('In the DLNA library')->assertSee('From Spotify');
    }
}
