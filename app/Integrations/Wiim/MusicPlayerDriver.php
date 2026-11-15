<?php

namespace App\Integrations\Wiim;

use App\Domain\Device\AvailableSource;
use App\Domain\Device\BatteryStatus;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\PlaybackModes;
use App\Domain\Device\RepeatMode;
use App\Domain\Device\Settings\DeviceInfo;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Common\DeviceQueue;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\BatteryInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Upnp\MusicPlayerDriver as UpnpDriver;
use Illuminate\Support\Facades\Cache;

/**
 * A WiiM (LinkPlay) streamer: everything the UPnP driver does for what the server plays on it (library queue, radio,
 * stream URLs), and the WiiM's own HTTP API for the rest: volume, mute and seek always, transport and the loop mode
 * while the WiiM plays something of its own (AirPlay, Spotify Connect, a preset ...), inputs and presets as sources,
 * device info, standby, battery. Built from the vendor document and never run on a device; see docs/architecture/wiim.md.
 */
class MusicPlayerDriver extends UpnpDriver implements BatteryInterface, PowerInterface, SourceActivationInterface, SourcesInterface
{
    private const PRESETS_TTL_SECONDS = 600;

    public function wiim(): WiimClient
    {
        return WiimClient::forDevice($this->device);
    }

    /** Did the server start what plays (queue, radio, a Spotify item)? Then the server's queue answers transport commands. */
    private function ours(): bool
    {
        $id = $this->device->id;

        return self::playing($id) !== null || self::radio($id) !== null || self::spotifyItem($id) !== null;
    }

    public function play(): void
    {
        $queue = DeviceQueue::for($this->device->id);

        if ($this->ours() || ($queue->current() !== null && $this->wiim()->playerStatus()['status'] === 'stop')) {
            parent::play();

            return;
        }

        $this->wiim()->player('resume');
    }

    public function pause(): void
    {
        $this->ours() ? parent::pause() : $this->wiim()->player('pause');
    }

    public function stop(): void
    {
        $this->ours() ? parent::stop() : $this->wiim()->player('stop');
    }

    public function next(): void
    {
        $this->ours() ? parent::next() : $this->wiim()->player('next');
    }

    public function previous(): void
    {
        $this->ours() ? parent::previous() : $this->wiim()->player('prev');
    }

    /** The API takes seconds (the status reports ms). */
    public function seek(int $seconds): void
    {
        $this->wiim()->player('seek:'.max(0, $seconds));
    }

    public function setVolume(int $volume): int
    {
        $volume = max(0, min(100, $volume));
        $this->wiim()->player('vol:'.$volume);
        Volume::updateVolume($this->device->id, $volume);

        return $volume;
    }

    public function getVolume(): int
    {
        return Volume::remember($this->device->id, fn () => $this->wiim()->playerStatus()['volume']);
    }

    public function mute(): void
    {
        $this->wiim()->player('mute:1');
        Volume::updateMuted($this->device->id, true);
    }

    public function unmute(): void
    {
        $this->wiim()->player('mute:0');
        Volume::updateMuted($this->device->id, false);
    }

    public function isMuted(): bool
    {
        return Volume::getMuted($this->device->id) ?? $this->wiim()->playerStatus()['muted'];
    }

    public function setShuffle(bool $shuffle): void
    {
        if ($this->ours()) {
            parent::setShuffle($shuffle);

            return;
        }

        [, $repeat] = WiimClient::LOOP_STATUS[$this->wiim()->playerStatus()['loop']] ?? [false, 'off'];
        $this->setLoop($shuffle, RepeatMode::from($repeat));
    }

    public function setRepeat(RepeatMode $mode): void
    {
        if ($this->ours()) {
            parent::setRepeat($mode);

            return;
        }

        [$shuffle] = WiimClient::LOOP_STATUS[$this->wiim()->playerStatus()['loop']] ?? [false, 'off'];
        $this->setLoop($shuffle, $mode);
    }

    /** One loop code carries both shuffle and repeat; repeat one and shuffle exclude each other (the single loop wins). */
    private function setLoop(bool $shuffle, RepeatMode $repeat): void
    {
        $code = match (true) {
            $repeat === RepeatMode::One => WiimClient::LOOP_SET['one'],
            $shuffle => $repeat === RepeatMode::All ? WiimClient::LOOP_SET['shuffle_all'] : WiimClient::LOOP_SET['shuffle_off'],
            default => $repeat === RepeatMode::All ? WiimClient::LOOP_SET['all'] : WiimClient::LOOP_SET['off'],
        };

        $this->wiim()->player('loopmode:'.$code);

        event(new PlaybackModesUpdated(
            deviceId: (string) $this->device->id,
            modes: new PlaybackModes($repeat === RepeatMode::One ? false : $shuffle, $repeat, null),
        ));
    }

    public function getDeviceInfo(): DeviceInfo
    {
        $status = $this->wiim()->statusEx();

        return new DeviceInfo(
            name: (string) ($status['DeviceName'] ?? $this->device->device_name),
            productType: $this->device->device_product_type,
            firmware: $status['firmware'] ?? null,
            macAddress: $status['MAC'] ?? null,
            renamable: false,
        );
    }

    public function setDeviceName(string $name): void
    {
        throw new UnsupportedOperationException('The WiiM HTTP API has no command to rename a device.');
    }

    /** `battery` is 0 on a mains model; the charging state is not in the document. */
    public function getBattery(): ?BatteryStatus
    {
        return self::batteryOf($this->wiim()->statusEx());
    }

    public static function batteryOf(array $statusEx): ?BatteryStatus
    {
        if ((string) ($statusEx['battery'] ?? '0') === '0' || !isset($statusEx['battery_percent'])) {
            return null;
        }

        return new BatteryStatus(max(0, min(100, (int) $statusEx['battery_percent'])), false);
    }

    /** Standby only: the document has `setShutdown:0` (shut down now), and no way to wake the device again. */
    public function standby(): void
    {
        $this->wiim()->command('setShutdown:0');
    }

    public function powerOn(): void
    {
        throw new UnsupportedOperationException('The WiiM HTTP API cannot wake a device from standby.');
    }

    /**
     * The inputs of `switchmode` (the document lists no per-model set, so all five are offered), then the presets
     * (`preset:{n}`). The active one is the `mode` of the player status.
     *
     * @return AvailableSource[]
     */
    public function getSources(): array
    {
        $mode = $this->wiim()->playerStatus()['mode'];

        $inputs = collect(WiimClient::INPUTS)->map(fn (array $input, string $id) => new AvailableSource(
            sourceId: $id, friendlyName: $input[0], sourceType: $input[1], category: 'music',
            inUse: false, borrowed: false, providerJid: null, providerName: null, active: $mode === $input[2],
        ))->values()->all();

        $presets = array_map(fn (array $p) => new AvailableSource(
            sourceId: "preset:{$p['number']}", friendlyName: $p['name'], sourceType: 'RADIO', category: 'radio',
            inUse: false, borrowed: false, providerJid: null, providerName: $p['source'] ?: 'Preset',
        ), $this->presets());

        return [...$inputs, ...$presets];
    }

    public function activateSource(string $sourceId): void
    {
        if (isset(WiimClient::INPUTS[$sourceId])) {
            $this->wiim()->player('switchmode:'.$sourceId);
        } elseif (preg_match('/^preset:(\d{1,2})$/', $sourceId, $m) && collect($this->presets())->contains('number', (int) $m[1])) {
            $this->wiim()->command('MCUKeyShortClick:'.(int) $m[1]);
        } else {
            throw new \InvalidArgumentException("This WiiM has no source {$sourceId}.");
        }

        // What the WiiM plays now is its own, not the server's queue.
        self::forgetPlaying($this->device->id);
        self::forgetRadio($this->device->id);
    }

    /** @return array<int, array{number: int, name: string, url: ?string, source: ?string, art: ?string}> */
    public function presets(): array
    {
        $key = "wiim:{$this->device->id}:presets";

        if ($presets = Cache::get($key)) {
            return $presets;
        }

        $presets = $this->wiim()->presets();
        $presets !== [] && Cache::put($key, $presets, self::PRESETS_TTL_SECONDS);

        return $presets;
    }

    /** @return string[] the named equaliser presets (not bass/treble, so no `sound_adjustment` capability) */
    public function eqPresets(): array
    {
        return $this->wiim()->eqList();
    }

    public function setEqPreset(string $name): void
    {
        if (!in_array($name, $this->eqPresets(), true)) {
            throw new \InvalidArgumentException("This WiiM has no equaliser preset {$name}.");
        }

        $this->wiim()->command('EQOn');
        $this->wiim()->command('EQLoad:'.$name);
    }

    public function setEqEnabled(bool $on): void
    {
        $this->wiim()->command($on ? 'EQOn' : 'EQOff');
    }
}
