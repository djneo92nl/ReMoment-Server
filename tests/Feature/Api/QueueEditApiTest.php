<?php

namespace Tests\Feature\Api;

use App\Domain\Device\State;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakeQueueEditDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** The queue edit endpoints: add, play next, remove, move, clear (see docs/api/queue.md). */
class QueueEditApiTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakePlayerDriver::reset();
    }

    private function device(string $driver = FakeQueueEditDriver::class): Device
    {
        return Device::factory()->driver($driver)->inState(State::Playing)->create();
    }

    private function album(): Album
    {
        $artist = Artist::create(['name' => 'Artist']);

        return Album::create(['name' => 'Album', 'artist_id' => $artist->id]);
    }

    public function test_capabilities_list_the_queue_edits_a_driver_has(): void
    {
        $device = $this->device();

        $capabilities = $this->getJson("/api/devices/{$device->id}")->json('data.capabilities');

        foreach (['queue_add', 'queue_next', 'queue_remove', 'queue_move', 'queue_clear'] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    public function test_a_track_is_appended(): void
    {
        $track = $this->makeLibraryTrack($this->album(), 'One');
        $device = $this->device();

        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $track->id])
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'next' => false, 'tracks' => 1]);

        $this->assertSame([['addToQueue', [$track->id]]], FakePlayerDriver::$calls);
    }

    public function test_an_album_goes_next_in_album_order(): void
    {
        $album = $this->album();
        $one = $this->makeLibraryTrack($album, 'One');
        $two = $this->makeLibraryTrack($album, 'Two');
        $device = $this->device();

        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'album', 'id' => $album->id, 'next' => true])
            ->assertOk()
            ->assertJsonPath('tracks', 2)
            ->assertJsonPath('next', true);

        $this->assertSame([['playNext', [$one->id, $two->id]]], FakePlayerDriver::$calls);
    }

    public function test_the_item_must_exist_and_the_type_be_known(): void
    {
        $device = $this->device();

        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'album', 'id' => 999])->assertStatus(422)->assertJsonValidationErrors('id');
        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'radio', 'id' => 1])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_remove_move_and_clear(): void
    {
        $device = $this->device();

        $this->deleteJson("/api/devices/{$device->id}/queue/2?expect=fake:2")->assertOk()->assertExactJson(['status' => 'ok', 'position' => 2]);
        $this->putJson("/api/devices/{$device->id}/queue/3", ['to' => 1])->assertOk()->assertExactJson(['status' => 'ok', 'position' => 1]);
        $this->deleteJson("/api/devices/{$device->id}/queue")->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertSame([['removeFromQueue', [2, 'fake:2']], ['moveInQueue', [3, 1, null]], ['clearQueue', null]], FakePlayerDriver::$calls);
        $this->putJson("/api/devices/{$device->id}/queue/1", [])->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_a_driver_that_cannot_edit_says_unsupported_per_capability(): void
    {
        $device = $this->device(FakePlayerDriver::class);
        $track = $this->makeLibraryTrack($this->album(), 'One');

        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $track->id])->assertStatus(422)->assertJsonPath('message', 'This device does not support queue_add.');
        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $track->id, 'next' => true])->assertStatus(422)->assertJsonPath('message', 'This device does not support queue_next.');
        $this->deleteJson("/api/devices/{$device->id}/queue/1")->assertStatus(422)->assertJsonPath('error', 'unsupported');
        $this->deleteJson("/api/devices/{$device->id}/queue")->assertStatus(422)->assertJsonPath('error', 'unsupported');
    }

    public function test_an_unreachable_device_is_503(): void
    {
        $device = Device::factory()->driver(FakeQueueEditDriver::class)->inState(State::Unreachable)->create();

        $this->deleteJson("/api/devices/{$device->id}/queue")->assertStatus(503)->assertJsonPath('error', 'unreachable');
    }

    public function test_a_stale_position_answers_invalid(): void
    {
        $device = $this->device();
        FakePlayerDriver::$throw = new \InvalidArgumentException('The queue has changed; reload it and try again.');

        $this->deleteJson("/api/devices/{$device->id}/queue/1?expect=old")->assertStatus(422)->assertJsonPath('error', 'invalid');
    }

    public function test_the_web_buttons_queue_on_the_device_and_flash_the_result(): void
    {
        $album = $this->album();
        $track = $this->makeLibraryTrack($album, 'One');
        $device = $this->device();

        $this->from('/albums/1')->post(route('devices.queue.add', $device), ['type' => 'album', 'id' => $album->id, 'label' => 'Album', 'next' => 1])
            ->assertRedirect('/albums/1')
            ->assertSessionHas('success', 'Playing "Album" next on Living Room.');

        $this->assertSame([['playNext', [$track->id]]], FakePlayerDriver::$calls);

        $plain = $this->device(FakePlayerDriver::class);
        $this->post(route('devices.queue.add', $plain), ['type' => 'album', 'id' => $album->id])->assertSessionHas('error');
    }

    public function test_the_album_page_offers_queue_buttons_only_when_the_pinned_player_can(): void
    {
        $album = $this->album();
        $this->makeLibraryTrack($album, 'One');
        $can = $this->device();
        $cannot = $this->device(\Tests\Support\FakeLibraryPlaybackDriver::class);

        $this->withCookie(\App\Support\SelectedDevice::COOKIE, (string) $can->id)->get(route('albums.show', $album))
            ->assertOk()->assertSee('data-test="album-queue_add"', false)->assertSee('data-test="album-queue_next"', false);

        $this->app->forgetScopedInstances();

        $this->withCookie(\App\Support\SelectedDevice::COOKIE, (string) $cannot->id)->get(route('albums.show', $album))
            ->assertOk()->assertDontSee('data-test="album-queue_add"', false);
    }
}
