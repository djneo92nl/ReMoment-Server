<?php

namespace Tests\Feature\Listeners;

use App\Domain\Device\DeviceListeners;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver as AseDriver;
use App\Integrations\BangOlufsen\Ase\Services\DeviceListener as AseListener;
use App\Integrations\Contracts\DeviceListenerInterface;
use App\Integrations\Sonos\MusicPlayerDriver as SonosDriver;
use App\Integrations\Sonos\Services\DeviceListener as SonosListener;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Integrations\Spotify\Services\DeviceListener as SpotifyListener;
use App\Models\Device;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Remoment\MozartDriver\MusicPlayerDriver as MozartDriver;
use Remoment\MozartDriver\Services\DeviceListener as MozartListener;
use Tests\TestCase;

class DeviceListenersTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $driver, ?string $ip = '192.168.1.20'): Device
    {
        return new Device(['device_name' => 'Test', 'device_driver' => $driver, 'ip_address' => $ip]);
    }

    public function test_each_driver_resolves_to_its_own_listener(): void
    {
        $this->assertInstanceOf(AseListener::class, DeviceListeners::for($this->device(AseDriver::class)));
        $this->assertInstanceOf(SonosListener::class, DeviceListeners::for($this->device(SonosDriver::class)));
        $this->assertInstanceOf(MozartListener::class, DeviceListeners::for($this->device(MozartDriver::class)));
    }

    public function test_every_registered_listener_implements_the_contract(): void
    {
        foreach (config('devices.listeners') as $driver => $listener) {
            $this->assertTrue(class_exists($driver), "{$driver} is not a class");
            $this->assertTrue(is_subclass_of($listener, DeviceListenerInterface::class), "{$listener} must implement DeviceListenerInterface");
        }
    }

    public function test_a_driver_without_a_listener_is_not_supported(): void
    {
        $device = $this->device('App\\Integrations\\Nobody\\MusicPlayerDriver');

        $this->assertFalse(DeviceListeners::supports($device));
        $this->assertNull(DeviceListeners::for($device));
    }

    public function test_spotify_only_listens_while_an_account_is_connected(): void
    {
        $device = $this->device(SpotifyDriver::class, null);

        $this->assertTrue(DeviceListeners::supports($device));
        $this->assertNull(DeviceListeners::for($device));

        Setting::set('spotify_refresh_token', 'token');

        $this->assertInstanceOf(SpotifyListener::class, DeviceListeners::for($device));
    }
}
