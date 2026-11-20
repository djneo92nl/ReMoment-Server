<?php

namespace Tests\Feature;

use App\Livewire\AddToScene;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeLibraryPlaybackDriver;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** "Add to scene" on the album, artist and playlist pages (8.1b). */
class AddToSceneTest extends TestCase
{
    use RefreshDatabase;

    private Device $speaker;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->speaker = Device::factory()->driver(FakeLibraryPlaybackDriver::class)->create(['device_name' => 'Kitchen']);
        Device::factory()->create(['device_name' => 'No library']);
    }

    public function test_the_pages_hide_the_button(): void
    {
        $artist = Artist::create(['name' => 'Portishead']);
        $album = Album::create(['name' => 'Dummy', 'artist_id' => $artist->id]);
        $playlist = Playlist::create(['name' => 'Sunday jazz']);

        $this->get(route('albums.show', $album))->assertOk()->assertDontSee('Add to scene');
        $this->get(route('artists.show', $artist))->assertOk()->assertDontSee('Add to scene');
        $this->get(route('playlists.show', $playlist))->assertOk()->assertDontSee('Add to scene');
    }

    public function test_it_appends_a_play_step_to_an_existing_scene(): void
    {
        $album = Album::create(['name' => 'Dummy', 'artist_id' => Artist::create(['name' => 'Portishead'])->id]);
        $scene = Scene::create(['name' => 'Evening', 'steps' => [['type' => 'delay', 'seconds' => 2]]]);

        Livewire::test(AddToScene::class, ['type' => 'album', 'id' => $album->id])
            ->call('toggle')
            ->assertSee('Kitchen')->assertDontSee('No library')
            ->set('sceneChoice', (string) $scene->id)
            ->set('deviceId', (string) $this->speaker->id)
            ->set('shuffle', true)
            ->call('add')
            ->assertHasNoErrors()
            ->assertSee('Added to')->assertSee('Edit the scene');

        $this->assertSame([
            ['type' => 'delay', 'seconds' => 2],
            ['operation' => 'playAlbum', 'device_id' => $this->speaker->id, 'params' => ['album_id' => $album->id, 'shuffle' => true]],
        ], $scene->fresh()->steps);
    }

    public function test_it_creates_a_new_scene_for_an_artist_and_a_playlist(): void
    {
        $artist = Artist::create(['name' => 'Portishead']);
        $playlist = Playlist::create(['name' => 'Sunday jazz']);

        Livewire::test(AddToScene::class, ['type' => 'artist', 'id' => $artist->id])
            ->set('sceneChoice', 'new')->set('newName', 'Trip hop')->set('deviceId', (string) $this->speaker->id)->call('add')->assertHasNoErrors();
        Livewire::test(AddToScene::class, ['type' => 'playlist', 'id' => $playlist->id])
            ->set('sceneChoice', 'new')->set('newName', 'Jazz')->set('deviceId', (string) $this->speaker->id)->call('add')->assertHasNoErrors();

        $this->assertSame([['operation' => 'playArtist', 'device_id' => $this->speaker->id, 'params' => ['artist_id' => $artist->id]]], Scene::where('name', 'Trip hop')->first()->steps);
        $this->assertSame('playPlaylist', Scene::where('name', 'Jazz')->first()->steps[0]['operation']);
    }

    public function test_it_asks_for_what_is_missing_and_refuses_a_full_scene(): void
    {
        $artist = Artist::create(['name' => 'Portishead']);

        Livewire::test(AddToScene::class, ['type' => 'artist', 'id' => $artist->id])->call('add')->assertHasErrors(['sceneChoice', 'deviceId']);
        Livewire::test(AddToScene::class, ['type' => 'artist', 'id' => $artist->id])
            ->set('sceneChoice', 'new')->set('deviceId', (string) $this->speaker->id)->call('add')->assertHasErrors(['newName']);

        $full = Scene::create(['name' => 'Full', 'steps' => array_fill(0, 50, ['type' => 'delay', 'seconds' => 1])]);
        Livewire::test(AddToScene::class, ['type' => 'artist', 'id' => $artist->id])
            ->set('sceneChoice', (string) $full->id)->set('deviceId', (string) $this->speaker->id)->call('add')
            ->assertSet('messageIsError', true);
        $this->assertCount(50, $full->fresh()->steps);
    }
}
