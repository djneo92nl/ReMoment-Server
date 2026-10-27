<?php

namespace App\Integrations\Philips;

use App\Domain\Device\AvailableSource;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\RemoteKey;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\DigitsInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\RemoteControlInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VideoDeviceInterface;
use App\Integrations\Upnp\MusicPlayerDriver as UpnpDriver;
use App\Models\DeviceMeta;
use App\Services\Network\WakeOnLan;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * A paired Philips Android TV, a video device like the ASE VideoPlayerDriver: everything the UPnP driver does
 * (playback, queue, volume, seek) plus what Jointspace adds: standby, remote keys, number keys, and the apps as
 * sources (Netflix, YouTube, the TV's own media player). The TV cannot be woken over the network and has no API to switch HDMI inputs
 * on current firmware, so those are not offered. See docs/architecture/philips-tv.md.
 */
class VideoPlayerDriver extends UpnpDriver implements DigitsInterface, PowerInterface, RemoteControlInterface, SourceActivationInterface, SourcesInterface, VideoDeviceInterface
{
    /** The app list barely changes: asked once in a while, not on every page. */
    private const APPS_TTL_SECONDS = 600;

    /**
     * Jointspace key names (`POST /6/input/key`) per `RemoteKey`. The firmware does not list the keys it accepts
     * (`GET /6/input/key` answers empty), so this is the static set the API documents. Volume stays with the
     * UPnP volume calls but the keys are offered too: a TV's remote has them, and they step by the TV's own amount.
     */
    private const KEYS = [
        'up' => 'CursorUp',
        'down' => 'CursorDown',
        'left' => 'CursorLeft',
        'right' => 'CursorRight',
        'ok' => 'Confirm',
        'back' => 'Back',
        'home' => 'Home',
        'menu' => 'Options',
        'source' => 'Source',
        'info' => 'Info',
        'play' => 'Play',
        'pause' => 'Pause',
        'stop' => 'Stop',
        'next' => 'Next',
        'previous' => 'Previous',
        'fast_forward' => 'FastForward',
        'rewind' => 'Rewind',
        'volume_up' => 'VolumeUp',
        'volume_down' => 'VolumeDown',
        'mute' => 'Mute',
        'channel_up' => 'ChannelStepUp',
        'channel_down' => 'ChannelStepDown',
        'red' => 'RedColour',
        'green' => 'GreenColour',
        'yellow' => 'YellowColour',
        'blue' => 'BlueColour',
    ];

    public function client(): JointspaceClient
    {
        return JointspaceClient::forDevice($this->device);
    }

    /** The TV's own volume (`/6/audio/volume`, its scale scaled to 0-100): the UPnP renderer is not there on every TV. */
    public function getVolume(): int
    {
        return Volume::remember($this->device->id, fn () => $this->readVolume()['percent']);
    }

    public function setVolume(int $volume): int
    {
        $volume = max(0, min(100, $volume));
        $scale = $this->client()->volume();
        $this->client()->setVolume((int) round($volume / 100 * $scale['max']), $scale['muted']);
        Volume::updateVolume($this->device->id, $volume);

        return $volume;
    }

    public function mute(): void
    {
        $this->setMuted(true);
    }

    public function unmute(): void
    {
        $this->setMuted(false);
    }

    public function isMuted(): bool
    {
        return Volume::getMuted($this->device->id) ?? $this->readVolume()['muted'];
    }

    /** @return array{percent: int, muted: bool} */
    public function readVolume(): array
    {
        $scale = $this->client()->volume();

        return ['percent' => (int) round($scale['current'] / $scale['max'] * 100), 'muted' => $scale['muted']];
    }

    private function setMuted(bool $muted): void
    {
        $scale = $this->client()->volume();
        $this->client()->setVolume($scale['current'], $muted);
        Volume::updateMuted($this->device->id, $muted);
    }

    /** A TV is woken over the network: through its own API while it still answers, else with Wake-on-LAN once its MAC is known. */
    public function canWakeOnLan(): bool
    {
        return $this->wakeMac() !== null;
    }

    public function powerOn(): void
    {
        try {
            $this->client()->wake(); // Standby with the network kept alive ("StandbyKeep") answers its API.

            return;
        } catch (UnsupportedOperationException|ConnectionException) {
            // Fully asleep: the packet below.
        }

        $mac = $this->wakeMac();

        if ($mac === null) {
            throw new UnsupportedOperationException('The TV is asleep and its MAC address is not known yet: it is read while the TV is on, so switch it on once.');
        }

        WakeOnLan::send($mac, $this->device->ip_address);
    }

    /** Reads the MAC from the TV and keeps it, once; the TV has to be on. */
    public function rememberMac(): ?string
    {
        if (($mac = $this->wakeMac()) !== null) {
            return $mac;
        }

        $mac = $this->client()->wakeMac();

        if ($mac !== null) {
            DeviceMeta::updateOrCreate(['device_id' => $this->device->id, 'key' => 'mac_address'], ['value' => $mac]);
        }

        return $mac;
    }

    private function wakeMac(): ?string
    {
        return $this->device->meta()->where('key', 'mac_address')->value('value');
    }

    public function standby(): void
    {
        $this->client()->key('Standby');
    }

    public function supportedKeys(): array
    {
        return array_map(fn (string $key) => RemoteKey::from($key), array_keys(self::KEYS));
    }

    public function sendKey(RemoteKey $key): void
    {
        $name = self::KEYS[$key->value] ?? throw new UnsupportedOperationException("This TV has no {$key->value} key.");

        $this->client()->key($name);
    }

    public function sendDigit(int $digit): void
    {
        if ($digit < 0 || $digit > 9) {
            throw new \InvalidArgumentException('A number key is 0-9.');
        }

        $this->client()->key("Digit{$digit}");
    }

    public function getSources(): array
    {
        $current = $this->client()->currentPackage();

        return array_map(fn (array $app) => new AvailableSource(
            sourceId: $app['id'],
            friendlyName: $app['label'],
            sourceType: 'APP',
            category: 'video',
            inUse: $app['id'] === $current,
            borrowed: false,
            providerJid: null,
            providerName: null,
            active: $current === null ? null : $app['id'] === $current,
        ), $this->apps());
    }

    public function activateSource(string $sourceId): void
    {
        $app = collect($this->apps())->firstWhere('id', $sourceId);

        if ($app === null) {
            throw new \InvalidArgumentException("This TV has no app {$sourceId}.");
        }

        $this->client()->launch($app['intent']);
    }

    /** @return array<int, array{id: string, label: string, intent: array}> */
    public function apps(): array
    {
        return Cache::remember("philips:{$this->device->id}:apps", self::APPS_TTL_SECONDS, fn () => $this->client()->applications());
    }
}
