<?php

namespace App\Integrations\Cast;

use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Models\Device;

/**
 * A Chromecast (a dongle on a TV's HDMI input) as a device: what is casting comes from `Services\DeviceListener`, and the
 * transport of the running session (play, pause, stop, next, previous, seek) is sent over the Cast media namespace
 * (`CastControls`). It has no volume, power, sources or queue of its own: the TV it is plugged into has those, and is its
 * host (workstream 2a, docs/architecture/sub-devices.md). A TV with Chromecast built in is not this driver: Cast there is
 * a second transport of the TV itself. **Unverified on hardware.**
 */
class MusicPlayerDriver implements MediaControlsInterface, MusicPlayerDriverInterface, SeekInterface
{
    public function __construct(public Device $device) {}

    public function play(): void
    {
        $this->controls()->send('PLAY');
    }

    public function pause(): void
    {
        $this->controls()->send('PAUSE');
    }

    public function stop(): void
    {
        $this->controls()->send('STOP');
    }

    public function next(): void
    {
        $this->controls()->send('QUEUE_NEXT');
    }

    public function previous(): void
    {
        $this->controls()->send('QUEUE_PREV');
    }

    public function seek(int $seconds): void
    {
        $this->controls()->send('SEEK', ['currentTime' => $seconds]);
    }

    private function controls(): CastControls
    {
        return new CastControls(app(CastConnection::class, ['host' => (string) $this->device->ip_address, 'timeout' => 3.0]));
    }
}
