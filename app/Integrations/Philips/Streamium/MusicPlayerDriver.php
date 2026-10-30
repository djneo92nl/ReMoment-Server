<?php

namespace App\Integrations\Philips\Streamium;

use App\Domain\Device\AvailableSource;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Upnp\MusicPlayerDriver as UpnpDriver;
use Illuminate\Support\Facades\Cache;

/**
 * A Philips Streamium HiFi player (MCi900): everything the UPnP driver does (library playback, server queue, volume,
 * seek) plus what its phone web remote adds: standby, the ten radio presets as sources, and play / pause / stop /
 * next / previous while a local source (internet radio) plays. The unit has no readable power state, so power is a
 * toggle and the server remembers what it sent. See docs/architecture/upnp-renderer.md (the MCi900 section).
 */
class MusicPlayerDriver extends UpnpDriver implements PowerInterface, SourceActivationInterface, SourcesInterface
{
    /** The presets barely change: asked once in a while, not on every page. */
    private const PRESETS_TTL_SECONDS = 600;

    /** The listener refreshes this every few seconds while a local source plays, so a command knows where to go. */
    private const LOCAL_TTL_SECONDS = 15;

    public function remote(): RemoteClient
    {
        return RemoteClient::forDevice($this->device);
    }

    public static function markLocal(int $deviceId): void
    {
        Cache::put("streamium:{$deviceId}:local", true, self::LOCAL_TTL_SECONDS);
    }

    public static function forgetLocal(int $deviceId): void
    {
        Cache::forget("streamium:{$deviceId}:local");
    }

    /** Is a local source (internet radio, ...) what the unit plays, rather than a URL we gave it over UPnP? */
    public static function isLocal(int $deviceId): bool
    {
        return Cache::has("streamium:{$deviceId}:local");
    }

    /** Did the server put the unit to standby itself? Nothing else tells: the unit has no power state to read. */
    public static function believedAsleep(int $deviceId): bool
    {
        return Cache::get("streamium:{$deviceId}:asleep", false);
    }

    public function standby(): void
    {
        $this->remote()->toggleStandby();
        Cache::forever("streamium:{$this->device->id}:asleep", true);
        self::forgetLocal($this->device->id);
    }

    /** Only wakes a unit the server put to standby: asking a unit that may be awake would put it to sleep. */
    public function powerOn(): void
    {
        if (!self::believedAsleep($this->device->id)) {
            throw new UnsupportedOperationException('The player has no power state to read, so it is only woken after the server put it to standby.');
        }

        $this->remote()->toggleStandby();
        Cache::forget("streamium:{$this->device->id}:asleep");
    }

    /** A live radio stream cannot be paused: the player's pause button mutes it (`play` stays 1, `mute` flips). */
    public function play(): void
    {
        if (!$this->local()) {
            parent::play();

            return;
        }

        $status = $this->remote()->status();

        if ($status !== null && ($status['paused'] || $status['muted'])) {
            $this->remote()->command('/CTRL$PLAY_PAUSE');
        }
    }

    public function pause(): void
    {
        if (!$this->local()) {
            parent::pause();

            return;
        }

        $status = $this->remote()->status();

        if ($status === null || (!$status['paused'] && !$status['muted'])) {
            $this->remote()->command('/CTRL$PLAY_PAUSE');
        }
    }

    public function stop(): void
    {
        if ($this->local()) {
            $this->remote()->command('/CTRL$STOP');
            self::forgetLocal($this->device->id);

            return;
        }

        parent::stop();
    }

    public function next(): void
    {
        $this->local() ? $this->remote()->command('/CTRL$NEXT') : parent::next();
    }

    public function previous(): void
    {
        $this->local() ? $this->remote()->command('/CTRL$PREV') : parent::previous();
    }

    /**
     * The unit's inputs, AUX and Disc, then its radio presets (`preset:{id}`, the unit's own list). The input
     * the unit is on is read from its play screen. FM is not offered: it could not be selected on the hardware.
     *
     * @return AvailableSource[]
     */
    public function getSources(): array
    {
        $screen = $this->remote()->screen();
        $station = $screen['source'] === 'radio' ? ($screen['item']['station'] ?? null) : null;

        $inputs = [
            'aux' => new AvailableSource(sourceId: 'aux', friendlyName: 'AUX', sourceType: 'LINE_IN', category: 'music', inUse: false, borrowed: false, providerJid: null, providerName: null, active: $screen['source'] === 'aux'),
            'disc' => new AvailableSource(sourceId: 'disc', friendlyName: 'Disc', sourceType: 'CD', category: 'music', inUse: false, borrowed: false, providerJid: null, providerName: null, active: $screen['source'] === 'disc'),
        ];

        $presets = array_map(fn (array $preset) => new AvailableSource(
            sourceId: "preset:{$preset['id']}",
            friendlyName: $preset['name'],
            sourceType: 'RADIO',
            category: 'radio',
            inUse: false,
            borrowed: false,
            providerJid: null,
            providerName: 'Internet radio',
            active: $station === null ? null : $preset['name'] === $station,
        ), $this->presets());

        return [...array_values($inputs), ...$presets];
    }

    public function activateSource(string $sourceId): void
    {
        if (isset(RemoteClient::INPUT_PAGES[$sourceId])) {
            $this->remote()->selectInput($sourceId);
        } else {
            $preset = preg_match('/^preset:(\d+)$/', $sourceId, $m)
                ? collect($this->presets())->firstWhere('id', (int) $m[1])
                : null;

            if ($preset === null) {
                throw new \InvalidArgumentException("This player has no source {$sourceId}.");
            }

            $this->remote()->playRadioPreset($preset['target']);
        }

        self::markLocal($this->device->id);
        Cache::forget("streamium:{$this->device->id}:asleep");
    }

    /** @return array<int, array{id: int, name: string, target: string}> */
    public function presets(): array
    {
        $key = "streamium:{$this->device->id}:presets";

        if ($presets = Cache::get($key)) {
            return $presets;
        }

        // An answer that parsed to nothing is not worth remembering.
        $presets = $this->remote()->radioPresets();

        if ($presets !== []) {
            Cache::put($key, $presets, self::PRESETS_TTL_SECONDS);
        }

        return $presets;
    }

    private function local(): bool
    {
        return self::isLocal($this->device->id);
    }
}
