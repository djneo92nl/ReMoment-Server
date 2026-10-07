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
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class PlayerBarTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(string $name = 'Living Room'): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => $name,
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => FakePlayerDriver::class,
            'device_driver_name' => 'Fake',
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
            ->assertSee('Choose a player');
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

    public function test_bar_renders_empty_state(): void
    {
        Livewire::test(PlayerBar::class)->assertSee('Choose a player');
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
