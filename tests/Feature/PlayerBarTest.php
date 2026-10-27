<?php

namespace Tests\Feature;

use App\Domain\Device\Cache\MultiRoom;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\MultiRoomStatus;
use App\Domain\Device\State;
use App\Livewire\PlayerBar;
use App\Models\Device;
use App\Support\SelectedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

class PlayerBarTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(string $name = 'Living Room'): Device
    {
        $device = Device::factory()->create([
            'device_name' => $name,
        ]);
        DeviceCache::updateState($device->id, State::Playing);

        return $device;
    }

    public function test_select_sets_cookie_and_clear_removes_it(): void
    {
        $device = $this->makeDevice();

        $this->post(route('player.select', $device))
            ->assertRedirect()
            ->assertCookie(SelectedDevice::COOKIE, (string) $device->id);

        $this->delete(route('player.clear'))
            ->assertRedirect()
            ->assertCookieExpired(SelectedDevice::COOKIE);
    }

    public function test_stale_cookie_is_ignored(): void
    {
        $this->withCookie(SelectedDevice::COOKIE, '999')
            ->get(route('radio.index'))
            ->assertOk()
            ->assertSee('Select player');
    }

    public function test_bar_shows_pinned_device(): void
    {
        $device = $this->makeDevice('Kitchen');

        $this->withCookie(SelectedDevice::COOKIE, (string) $device->id)
            ->get(route('radio.index'))
            ->assertOk()
            ->assertDontSee('Choose a player to play on')
            ->assertSee('Unreachable');
    }

    public function test_bar_is_gone_without_a_pinned_player_and_the_sidebar_offers_the_picker(): void
    {
        Livewire::test(PlayerBar::class)->assertDontSee('Choose a player')->assertDontSee('Up next');

        $this->get(route('radio.index'))->assertSee('Select player');
    }

    public function test_picker_panel_lists_players_and_a_no_player_option(): void
    {
        $device = $this->makeDevice('Kitchen');
        $this->makeDevice('Garage'); // no listener running: unreachable, left out
        cache()->put("listener_running_{$device->id}", true, 60);

        Livewire::test(PlayerBar::class)
            ->call('openPanel', 'players')
            ->assertDontSee('Garage')
            ->assertSee('Choose a player')
            ->assertSee('No player')
            ->assertSee('Kitchen');
    }

    public function test_play_button_posts_directly_to_pinned_device(): void
    {
        $pinned = $this->makeDevice('Kitchen');
        $html = $this->renderPlayButton(collect([$pinned]), $pinned);

        $this->assertStringContainsString('action="'.url('albums/1/play/'.$pinned->id).'"', $html);
    }

    public function test_play_button_falls_back_to_modal_when_pinned_device_cannot_play(): void
    {
        $pinned = $this->makeDevice('Kitchen');
        $other = $this->makeDevice('Office');
        $html = $this->renderPlayButton(collect([$other]), $pinned);

        $this->assertStringNotContainsString('action="'.url('albums/1/play/'.$pinned->id).'"', $html);
        $this->assertStringContainsString("open-modal', 'play-x'", $html);
    }

    public function test_bar_lists_every_player_of_a_multiroom_session(): void
    {
        $host = $this->makeDevice('Kitchen');
        $guest = $this->makeDevice('Office');
        $host->meta()->create(['key' => 'ase_jid', 'value' => 'host@jid']);
        $guest->meta()->create(['key' => 'ase_jid', 'value' => 'guest@jid']);
        MultiRoom::put($host->id, MultiRoomStatus::hosting(['guest@jid']));
        MultiRoom::put($guest->id, MultiRoomStatus::joined('host@jid'));

        $this->withCookie(SelectedDevice::COOKIE, (string) $guest->id)
            ->get(route('radio.index'))
            ->assertOk()
            ->assertSee('Office + Kitchen');
    }

    public function test_panels_open_only_for_a_pinned_player(): void
    {
        Livewire::test(PlayerBar::class)
            ->call('openPanel', 'queue')
            ->assertSet('panel', null)
            ->call('openPanel', 'players')
            ->assertSet('panel', 'players')
            ->call('closePanel')
            ->assertSet('panel', null);
    }

    public function test_bar_opens_the_queue_and_multiroom_panels(): void
    {
        $device = $this->makeDevice('Kitchen');
        cache()->put("listener_running_{$device->id}", true, 60);

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id)
            ->test(PlayerBar::class)
            ->call('togglePanel', 'queue')
            ->assertSet('panel', 'queue')
            ->assertSee('Up next')
            ->call('togglePanel', 'queue')
            ->assertSet('panel', null)
            ->call('openPanel', 'multiroom')
            ->assertSet('panel', 'multiroom');
    }

    public function test_the_move_panel_moves_playback_to_the_picked_device(): void
    {
        \Tests\Support\FakePlayerDriver::reset();
        config(['playback.move_wait_seconds' => 0]);
        $from = $this->makeDevice('Kitchen');
        $to = Device::factory()->driver(\Tests\Support\FakeMovableDriver::class)->create(['device_name' => 'Bedroom']);
        DeviceCache::updateState($to->id, State::Playing);
        cache()->put("listener_running_{$to->id}", true, 60);
        cache()->put("listener_running_{$from->id}", true, 60);
        DeviceCache::updateNowPlaying($from->id, new \App\Domain\Media\NowPlaying(state: 'playing', platform: 'radio', radio: new \App\Domain\Media\Radio(name: 'Radio 1')));
        \App\Models\RadioStation::create(['name' => 'Radio 1']);

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $from->id)
            ->test(PlayerBar::class)
            ->call('openPanel', 'move')
            ->assertSee('Move playback to')
            ->assertSee('Bedroom')
            ->call('movePlayback', $to->id)
            ->assertSet('moveError', null)
            ->assertSet('moved.name', 'Bedroom');

        $this->assertNotEmpty(array_filter(\Tests\Support\FakePlayerDriver::$calls, fn ($c) => $c[0] === 'playRadioStation'));
    }

    public function test_the_move_panel_shows_why_a_move_is_refused(): void
    {
        $from = $this->makeDevice('Kitchen');
        $to = $this->makeDevice('Bedroom');
        cache()->put("listener_running_{$to->id}", true, 60);
        cache()->put("listener_running_{$from->id}", true, 60);
        DeviceCache::updateNowPlaying($from->id, new \App\Domain\Media\NowPlaying(state: 'playing', source: new \App\Domain\Media\Source(name: 'Line-in', sourceType: 'LINEIN')));

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $from->id)
            ->test(PlayerBar::class)
            ->call('openPanel', 'move')
            ->call('movePlayback', $to->id)
            ->assertSet('moved', null)
            ->assertSee("can't be moved");
    }

    public function test_the_queue_panel_edits_the_queue_with_the_row_it_was_shown_for(): void
    {
        \Tests\Support\FakePlayerDriver::reset();
        $device = Device::factory()->driver(\Tests\Support\FakeQueueEditDriver::class)->create();
        DeviceCache::updateState($device->id, State::Playing);
        cache()->put("listener_running_{$device->id}", true, 60);

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id)
            ->test(PlayerBar::class)
            ->call('openPanel', 'queue')
            ->assertSee('Remove from the queue')
            ->assertSee('Clear')
            ->call('removeFromQueue', 1, 'fake:1')
            ->call('moveInQueue', 2, 1, null)
            ->call('clearQueue')
            ->assertSet('queueError', null);

        $this->assertSame([['removeFromQueue', [1, 'fake:1']], ['moveInQueue', [2, 1, null]], ['clearQueue', null]], array_values(array_filter(\Tests\Support\FakePlayerDriver::$calls, fn ($call) => $call[0] !== 'getUpNext')));
    }

    public function test_a_refused_queue_edit_is_shown(): void
    {
        \Tests\Support\FakePlayerDriver::reset();
        $device = Device::factory()->driver(\Tests\Support\FakeQueueEditDriver::class)->create();
        DeviceCache::updateState($device->id, State::Playing);
        \Tests\Support\FakePlayerDriver::$throw = new \InvalidArgumentException('The queue has changed; reload it and try again.');

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id)
            ->test(PlayerBar::class)
            ->call('removeFromQueue', 1, 'old')
            ->assertSet('queueError', 'The queue has changed; reload it and try again.');
    }

    private function renderPlayButton($devices, Device $pinned): string
    {
        $this->app->instance('request', Request::create('/', 'GET', [], [SelectedDevice::COOKIE => (string) $pinned->id]));
        $this->app->forgetScopedInstances();

        return Blade::render(
            '<x-play-button name="play-x" :devices="$devices" action-template="'.url('albums/1/play').'/{id}">Go</x-play-button>',
            ['devices' => $devices]
        );
    }

    public function test_bar_draws_the_waveform_of_a_playing_local_track(): void
    {
        $device = $this->makeDevice('Kitchen');
        cache()->put("listener_running_{$device->id}", true, 60);
        $artist = \App\Models\Media\Artist::create(['name' => 'Wave Artist', 'source' => 'dlna']);
        $track = \App\Models\Media\Track::create(['name' => 'Wave Song', 'artist_id' => $artist->id, 'source' => 'dlna', 'duration' => 200]);
        $location = \App\Models\StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir()], 'role' => 'library']);
        $file = \App\Models\File::create(['storage_location_id' => $location->id, 'path' => 'w.flac', 'hash' => 'w1', 'size' => 1, 'mtime' => 1, 'status' => \App\Models\File::PRESENT, 'extension' => 'flac']);
        \App\Models\TrackFile::create(['track_id' => $track->id, 'file_id' => $file->id, 'role' => \App\Models\TrackFile::ORIGINAL, 'is_primary' => true]);
        \App\Models\FileAnalysis::create(['file_id' => $file->id, 'content_hash' => 'w1', 'waveform' => [10, 50, 100, 50, 10]]);

        DeviceCache::updateNowPlaying($device->id, new \App\Domain\Media\NowPlaying(
            track: new \App\Domain\Media\TrackData(name: 'Wave Song', artist: new \App\Domain\Media\ArtistData(name: 'Wave Artist'), duration: 200),
        ));

        Livewire::withCookie(SelectedDevice::COOKIE, (string) $device->id)
            ->test(PlayerBar::class)
            ->assertSee('aria-label="Waveform"', false);
    }
}
