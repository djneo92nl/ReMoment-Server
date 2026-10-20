<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Livewire\DeviceQueue;
use App\Livewire\PlayerBar;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Support\SelectedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakeQueueEditDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Queue management phase 4: the MQTT queue ping, drag-to-reorder markup, queue buttons on more pages, the /receiver remove button. */
class QueuePolishTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakePlayerDriver::reset();
    }

    private function device(): Device
    {
        return Device::factory()->driver(FakeQueueEditDriver::class)->inState(State::Playing)->create();
    }

    private function queuePings(Device $device): array
    {
        return array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === "remoment/player/{$device->id}/queue"));
    }

    private function album(): Album
    {
        return Album::create(['name' => 'Album', 'artist_id' => Artist::create(['name' => 'Artist'])->id]);
    }

    public function test_a_rest_queue_edit_pings_the_queue_topic_without_retaining(): void
    {
        $device = $this->device();
        $track = $this->makeLibraryTrack($this->album(), 'One');

        $this->postJson("/api/devices/{$device->id}/queue", ['type' => 'track', 'id' => $track->id])->assertOk();
        $this->deleteJson("/api/devices/{$device->id}/queue/1")->assertOk();
        $this->putJson("/api/devices/{$device->id}/queue/2", ['to' => 1])->assertOk();
        $this->deleteJson("/api/devices/{$device->id}/queue")->assertOk();

        $pings = $this->queuePings($device);
        $this->assertCount(4, $pings);
        $this->assertFalse($pings[0]['retain']);
    }

    public function test_a_refused_edit_does_not_ping(): void
    {
        $device = $this->device();
        FakePlayerDriver::$throw = new \InvalidArgumentException('The queue has changed');

        $this->deleteJson("/api/devices/{$device->id}/queue/1?expect=old")->assertStatus(422);

        $this->assertSame([], $this->queuePings($device));
    }

    public function test_the_web_buttons_and_the_livewire_edits_ping_too(): void
    {
        $device = $this->device();
        $track = $this->makeLibraryTrack($this->album(), 'One');

        $this->post(route('devices.queue.add', $device), ['type' => 'track', 'id' => $track->id])->assertSessionHas('success');
        $this->assertCount(1, $this->queuePings($device));

        Livewire::test(DeviceQueue::class, ['device' => $device])->call('removeFromQueue', 1, null)->call('moveInQueue', 2, 1, null);
        $this->assertCount(3, $this->queuePings($device));
    }

    public function test_up_next_rows_are_draggable_only_with_queue_move(): void
    {
        $device = $this->device();
        DeviceCache::updateState($device->id, State::Playing);
        cache()->put("listener_running_{$device->id}", true, 60);

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id)->test(PlayerBar::class)->call('openPanel', 'queue')
            ->assertSeeHtml('x-data="queueSort"')->assertSeeHtml('draggable="true"')->assertSeeHtml('data-pos="1"');

        Livewire::test(DeviceQueue::class, ['device' => $device])->call('load')
            ->assertSeeHtml('x-data="queueSort"')->assertSeeHtml('draggable="true"');

        $plain = Device::factory()->driver(FakePlayerDriver::class)->inState(State::Playing)->create();
        Livewire::test(DeviceQueue::class, ['device' => $plain])->call('load')->assertDontSeeHtml('draggable="true"');
    }

    public function test_the_live_script_knows_the_drag_handler_and_the_receiver_has_remove(): void
    {
        $this->assertStringContainsString("Alpine.data('queueSort'", file_get_contents(public_path('js/remoment-live.js')));

        $this->get('/receiver')->assertOk()->assertSee("msg.kind === 'queue'", false)->assertSee('data-remove', false);
    }

    public function test_queue_buttons_on_the_playlist_and_artist_pages(): void
    {
        $device = $this->device();
        $album = $this->album();
        $track = $this->makeLibraryTrack($album, 'One');
        $playlist = Playlist::create(['name' => 'Mix']);
        $playlist->tracks()->attach($track->id, ['position' => 1]);

        $this->withCookie(SelectedDevice::COOKIE, (string) $device->id)->get(route('playlists.show', $playlist))
            ->assertOk()->assertSee('data-test="track-queue_add"', false)->assertSee('data-test="playlist-queue_next"', false);

        $this->app->forgetScopedInstances();

        $this->withCookie(SelectedDevice::COOKIE, (string) $device->id)->get(route('artists.show', $album->artist))
            ->assertOk()->assertSee('data-test="artist-queue_add"', false);

        $this->app->forgetScopedInstances();

        $cannot = Device::factory()->driver(\Tests\Support\FakeLibraryPlaybackDriver::class)->inState(State::Playing)->create();
        $this->withCookie(SelectedDevice::COOKIE, (string) $cannot->id)->get(route('playlists.show', $playlist))->assertOk()->assertDontSee('data-test="track-queue_add"', false);
    }
}
