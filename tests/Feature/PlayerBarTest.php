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

    private function renderPlayButton($devices, Device $pinned): string
    {
        $this->app->instance('request', Request::create('/', 'GET', [], [SelectedDevice::COOKIE => (string) $pinned->id]));
        $this->app->forgetScopedInstances();

        return Blade::render(
            '<x-play-button name="play-x" :devices="$devices" action-template="'.url('albums/1/play').'/{id}">Go</x-play-button>',
            ['devices' => $devices]
        );
    }
}
