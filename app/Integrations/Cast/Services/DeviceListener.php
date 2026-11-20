<?php

namespace App\Integrations\Cast\Services;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\ListenerStatus;
use App\Domain\Device\State;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Integrations\Cast\CastConnection;
use App\Integrations\Cast\CastNowPlaying;
use App\Integrations\Common\ListenerBackoff;
use App\Integrations\Contracts\DeviceListenerInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * Polls a Chromecast every few seconds (`CastNowPlaying`: one connection, receiver status and the media status of the
 * running app) and reports what is casting: the app and, when it has a media session, the title, artwork, state and
 * position. A receiver that shows its idle backdrop (the ambient app), runs no app or reports standby is `Standby`:
 * the backdrop is never a cast session. `isActiveInput` is not used (a dongle only reports it over CEC), so this says
 * what the dongle plays, not whether the TV shows it: the host's link says that. An unreachable receiver is
 * `Unreachable` and backs off. **Unverified on a real Chromecast.**
 *
 * The cast session starting (Standby to Playing) is what the follow rule of a link reacts to
 * (`App\Domain\SubDevices\FollowRule`), so the dongle must stay reachable while its TV is in standby: powered from
 * its own adapter, not from the TV's USB port (docs/architecture/beovision-tv.md, "Chromecast on HDMI 1").
 */
class DeviceListener implements DeviceListenerInterface
{
    public const EVERY_SECONDS = 4;

    /** What was last reported as playing, so an unchanged status raises no event. */
    private ?string $signature = null;

    private ?int $lastProgress = null;

    /** @param  ?\Closure  $reader  returns CastNowPlaying::read()'s array; for tests */
    public function __construct(private readonly string $host, private readonly ?\Closure $reader = null) {}

    public static function forDevice(Device $device): ?static
    {
        return $device->ip_address ? new static((string) $device->ip_address) : null;
    }

    public function listen(string $deviceId)
    {
        $backoff = new ListenerBackoff;

        DeviceCache::updateState((int) $deviceId, State::Unreachable);

        while (true) {
            $hadError = false;

            try {
                $first = true;

                while (true) {
                    DeviceCache::markListenerAlive($deviceId);
                    $this->poll($deviceId);

                    if ($first) {
                        ListenerStatus::recovered($deviceId);
                        $first = false;
                    }

                    sleep(self::EVERY_SECONDS);
                }
            } catch (\Throwable $e) {
                $hadError = true;
                ListenerStatus::error($deviceId, $e);
                Log::debug("Cast listener [{$deviceId}]: {$e->getMessage()}");
            } finally {
                DeviceCache::forgetListener($deviceId);
                DeviceCache::updateState((int) $deviceId, State::Unreachable);
                $this->signature = $this->lastProgress = null;
            }

            sleep($backoff->next($hadError));
        }
    }

    /** One look at the receiver; throws when it does not answer. */
    public function poll(string $deviceId): void
    {
        $reading = ($this->reader ?? fn () => (new CastNowPlaying(new CastConnection($this->host, timeout: 3.0)))->read())();
        $now = $reading['now'] ?? null;

        if (($reading['standby'] ?? false) || $now === null) {
            $this->idle($deviceId);

            return;
        }

        $signature = md5(json_encode([$now->source?->connector, $now->track?->name, $now->track?->artist?->name, $now->state]));

        if ($signature !== $this->signature) {
            event(new NowPlayingUpdated(deviceId: $deviceId, nowPlaying: $now, sourceType: 'media'));
            $this->signature = $signature;
            $this->lastProgress = null;

            if ($now->state === 'paused') {
                DeviceCache::updateState((int) $deviceId, State::Paused);
            }
        }

        if ($now->state === 'playing' && ($now->track?->duration ?? 0) > 0 && $now->position !== $this->lastProgress) {
            event(new ProgressUpdated(deviceId: $deviceId, progress: $now->position));
            $this->lastProgress = $now->position;
        }
    }

    /** Nothing is casting: the ambient backdrop, no app, or standby. */
    private function idle(string $deviceId): void
    {
        if ($this->signature !== null) {
            event(new NowPlayingEnded(deviceId: $deviceId));
        } elseif (DeviceCache::getState((int) $deviceId) === State::Unreachable) {
            DeviceCache::updateState((int) $deviceId, State::Standby);
        }

        $this->signature = $this->lastProgress = null;
    }
}
