<?php

namespace App\Integrations\Philips\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Philips\VideoPlayerDriver;
use App\Integrations\Upnp\Services\DeviceListener as UpnpListener;

/**
 * The UPnP listener, plus: while the TV plays nothing over UPnP, the app in front (Netflix, YouTube, ...) is
 * reported as the playing source, the way Sonos reports its TV input. Asked every few seconds only.
 */
class DeviceListener extends UpnpListener
{
    private const ACTIVITY_EVERY_SECONDS = 3;

    /** Packages that are the TV's menu, not something playing. */
    private const IDLE_PACKAGES = ['com.google.android.tvlauncher', 'com.google.android.apps.tv.launcherx', 'org.droidtv.nettvbrowser'];

    private ?string $activePackage = null;

    private int $nextActivityCheck = 0;

    public function poll(string $deviceId): void
    {
        parent::poll($deviceId);

        if (!$this->transportIdle) {
            $this->activePackage = null;

            return;
        }

        if (now()->timestamp < $this->nextActivityCheck) {
            return;
        }

        $this->nextActivityCheck = now()->timestamp + self::ACTIVITY_EVERY_SECONDS;
        $this->reportActivity($deviceId);
    }

    /** An app in front is the TV playing, whatever the UPnP side holds: no flapping between the two each poll. */
    protected function idleState(): State
    {
        return $this->activePackage !== null ? State::Playing : State::Standby;
    }

    private function reportActivity(string $deviceId): void
    {
        /** @var VideoPlayerDriver $driver */
        $driver = $this->device->driver;

        try {
            $package = $driver->client()->currentPackage();
        } catch (\Throwable) {
            return; // The UPnP side already tells reachable from not.
        }

        if ($package !== null && in_array($package, self::IDLE_PACKAGES, true)) {
            $package = null;
        }

        if ($package === $this->activePackage) {
            return;
        }

        $this->activePackage = $package;

        if ($package === null) {
            event(new NowPlayingEnded(deviceId: $deviceId));
            DeviceCache::updateState((int) $deviceId, State::Standby);

            return;
        }

        $label = collect($driver->apps())->firstWhere('id', $package)['label'] ?? $package;

        event(new NowPlayingUpdated(
            deviceId: $deviceId,
            nowPlaying: new NowPlaying(
                state: 'playing',
                type: 'video',
                platform: 'media',
                source: new Source(name: $label, category: 'video', sourceType: 'APP', connector: $package),
            ),
            sourceType: 'video',
            timestamp: null,
        ));
        DeviceCache::updateState((int) $deviceId, State::Playing);
    }
}
