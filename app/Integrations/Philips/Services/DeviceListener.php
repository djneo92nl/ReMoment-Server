<?php

namespace App\Integrations\Philips\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
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

    /** Packages that are the TV's menu or launcher: the TV is on, at its home screen. */
    private const IDLE_PACKAGES = ['com.google.android.tvlauncher', 'com.google.android.apps.tv.launcherx', 'org.droidtv.nettvbrowser', 'me.efesser.flauncher'];

    private const CAST_EVERY_SECONDS = 5;

    private const CAST_RETRY_AFTER_FAILURE_SECONDS = 300;

    /** A TV that does not answer as a UPnP renderer is asked again only this often. */
    private const UPNP_RETRY_SECONDS = 60;

    private ?string $activePackage = null;

    private bool $upnpDown = false;

    private int $nextUpnpCheck = 0;

    private ?string $reported = null;

    private int $nextCastCheck = 0;

    /** @var array{now: ?NowPlaying, standby: bool, external: bool}|null */
    private ?array $lastCast = null;

    private ?int $lastProgress = null;

    private int $nextActivityCheck = 0;

    public function poll(string $deviceId): void
    {
        // Some TVs have no UPnP renderer at all (the lab TV does not answer a search): Jointspace and Cast then
        // carry the listener, and the TV is unreachable only when Jointspace does not answer either.
        if (now()->timestamp >= $this->nextUpnpCheck) {
            try {
                parent::poll($deviceId);
                $this->upnpDown = false;
            } catch (\Throwable) {
                $this->upnpDown = true;
                $this->transportIdle = true;
                $this->nextUpnpCheck = now()->timestamp + self::UPNP_RETRY_SECONDS;
            }
        }

        if (!$this->transportIdle) {
            $this->activePackage = null;

            return;
        }

        if (now()->timestamp < $this->nextActivityCheck) {
            return;
        }

        $this->nextActivityCheck = now()->timestamp + self::ACTIVITY_EVERY_SECONDS;
        $this->reportJointspaceVolume($deviceId);
        $this->reportActivity($deviceId);
    }

    /** An app in front is the TV playing, whatever the UPnP side holds: no flapping between the two each poll. */
    protected function idleState(): State
    {
        return $this->activePackage !== null ? State::Playing : State::Standby;
    }

    /** Volume and mute from Jointspace, whatever the UPnP side does. */
    protected function reportVolume(\App\Integrations\Upnp\Renderer $renderer, string $deviceId): void
    {
        $this->reportJointspaceVolume($deviceId);
    }

    private function reportJointspaceVolume(string $deviceId): void
    {
        if (time() < $this->nextVolumeCheck) {
            return;
        }

        $this->nextVolumeCheck = time() + self::VOLUME_EVERY_SECONDS;

        try {
            $reading = $this->device->driver->readVolume();
        } catch (\Throwable) {
            return;
        }

        if ($reading['percent'] !== $this->lastVolume || $reading['muted'] !== $this->lastMuted) {
            event(new \App\Events\Device\VolumeUpdated(deviceId: $deviceId, volume: $reading['percent'], muted: $reading['muted']));
            $this->lastVolume = $reading['percent'];
            $this->lastMuted = $reading['muted'];
        }
    }

    private function reportActivity(string $deviceId): void
    {
        /** @var VideoPlayerDriver $driver */
        $driver = $this->device->driver;

        try {
            $package = $driver->client()->currentPackage();
        } catch (\Throwable $e) {
            if ($this->upnpDown) {
                throw $e; // Neither side answers: the TV is off or unreachable.
            }

            return; // The UPnP side already tells reachable from not.
        }

        // The TV's own power state says standby; its menu or launcher in front is the TV on, at its home screen.
        try {
            if ($driver->client()->powerState() === 'Standby') {
                $package = null;
            }
        } catch (\Throwable) {
            // Not every firmware has powerstate.
        }

        $reading = $this->castReading();
        $cast = $reading['now'] ?? null;

        if ($reading['standby'] ?? false) {
            $package = null;
        }

        $external = $reading['external'] ?? false;
        $signature = $package === null ? null : $package.'|'.($cast?->track?->name ?? '').'|'.($cast?->state ?? '').'|'.(int) $external;

        $this->reportProgress($deviceId, $cast);

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

        $label = $this->label($driver, $package, $external);

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

    /** `org.droidtv.xtv` is the TV's own layer: the tuner, or (Cast says so) another input such as HDMI. */
    private function label(VideoPlayerDriver $driver, string $package, bool $external): string
    {
        if (in_array($package, self::IDLE_PACKAGES, true)) {
            return 'Home screen';
        }

        if ($package === 'org.droidtv.xtv') {
            return $external ? 'HDMI input' : 'TV';
        }

        return collect($driver->apps())->firstWhere('id', $package)['label'] ?? $package;
    }

    /** The progress of casted media, in percent, when it moved. */
    private function reportProgress(string $deviceId, ?NowPlaying $cast): void
    {
        $duration = $cast?->track?->duration;
        $percent = $duration ? min(100, (int) round($cast->position / $duration * 100)) : null;

        if ($percent !== null && $cast->state === 'playing' && $percent !== $this->lastProgress) {
            event(new ProgressUpdated(deviceId: $deviceId, progress: $percent));
        }

        $this->lastProgress = $percent;
    }

    /** The casted title, shown under the app's own name (Jointspace knows the label, Cast only the receiver's). */
    private function withApp(NowPlaying $cast, string $label, string $package): NowPlaying
    {
        $cast->source = new Source(name: $label, category: 'video', sourceType: 'APP', connector: $package);

        return $cast;
    }

    /** What Cast reports, at most every few seconds; null when there is no media session, or the TV has no Cast. */
    private function castReading(): ?array
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
