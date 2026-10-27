<?php

namespace App\Integrations\Philips\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\Cast\CastConnection;
use App\Integrations\Cast\CastNowPlaying;
use App\Integrations\Philips\VideoPlayerDriver;
use App\Integrations\Upnp\Services\DeviceListener as UpnpListener;

/**
 * The UPnP listener, plus: while the TV plays nothing over UPnP, the app in front (Netflix, YouTube, ...) is
 * reported as the playing source, the way Sonos reports its TV input. Asked every few seconds only. A TV with
 * Chromecast built in also answers on Cast (port 8009): while an app is in front, the title, artist and artwork
 * of a casted media session (YouTube, a phone casting) replace the bare app name. A TV that does not answer Cast
 * is left alone for a few minutes.
 */
class DeviceListener extends UpnpListener
{
    private const ACTIVITY_EVERY_SECONDS = 3;

    /** Packages that are the TV's menu, not something playing. */
    private const IDLE_PACKAGES = ['com.google.android.tvlauncher', 'com.google.android.apps.tv.launcherx', 'org.droidtv.nettvbrowser'];

    private const CAST_EVERY_SECONDS = 5;

    private const CAST_RETRY_AFTER_FAILURE_SECONDS = 300;

    private ?string $activePackage = null;

    private ?string $reported = null;

    private int $nextCastCheck = 0;

    private ?NowPlaying $lastCast = null;

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

        if ($package === null) {
            $this->lastCast = null;
            $this->nextCastCheck = 0;
        }

        $cast = $package === null ? null : $this->castNowPlaying();
        $signature = $package === null ? null : $package.'|'.($cast?->track?->name ?? '').'|'.($cast?->state ?? '');

        if ($package === $this->activePackage && $signature === $this->reported) {
            return;
        }

        $this->activePackage = $package;
        $this->reported = $signature;

        if ($package === null) {
            event(new NowPlayingEnded(deviceId: $deviceId));
            DeviceCache::updateState((int) $deviceId, State::Standby);

            return;
        }

        $label = collect($driver->apps())->firstWhere('id', $package)['label'] ?? $package;

        event(new NowPlayingUpdated(
            deviceId: $deviceId,
            nowPlaying: $cast?->track !== null ? $this->withApp($cast, $label, $package) : new NowPlaying(
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

    /** The casted title, shown under the app's own name (Jointspace knows the label, Cast only the receiver's). */
    private function withApp(NowPlaying $cast, string $label, string $package): NowPlaying
    {
        $cast->source = new Source(name: $label, category: 'video', sourceType: 'APP', connector: $package);

        return $cast;
    }

    /** What Cast reports, at most every few seconds; null when there is no media session, or the TV has no Cast. */
    private function castNowPlaying(): ?NowPlaying
    {
        if (now()->timestamp < $this->nextCastCheck) {
            return $this->lastCast;
        }

        $this->nextCastCheck = now()->timestamp + self::CAST_EVERY_SECONDS;

        try {
            return $this->lastCast = (new CastNowPlaying(new CastConnection((string) $this->device->ip_address, timeout: 2.0)))->read();
        } catch (\Throwable) {
            $this->nextCastCheck = now()->timestamp + self::CAST_RETRY_AFTER_FAILURE_SECONDS;

            return $this->lastCast = null;
        }
    }
}
