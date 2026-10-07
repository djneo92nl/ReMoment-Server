<?php

namespace App\Livewire;

use App\Domain\Device\Capabilities;
use App\Domain\Device\DeviceCapabilities;
use App\Domain\Device\State;
use App\Integrations\Contracts\BluetoothInterface;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\DigitsInterface;
use App\Integrations\Contracts\NetworkSettingsInterface;
use App\Integrations\Contracts\SleepTimerInterface;
use App\Integrations\Contracts\SoundAdjustmentInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\WiredSpeakersInterface;
use App\Integrations\Contracts\WirelessSpeakersInterface;
use App\Listeners\Device\SwitchToDefaultSource;
use App\Models\Device;
use Livewire\Component;

/**
 * The settings of one device, section by section for whatever capabilities
 * its driver and hardware have (sound, Bluetooth, device info, sleep timer, number keys, network, wireless speakers). A section that
 * fails shows its own error and leaves the others working. Admin only: the
 * page sits behind the login, and every action checks it again.
 */
class DeviceSettings extends Component
{
    private const NETWORK_APPLIED = 'Applied. The device may take a moment and may come back on a new address; use Refresh once it is up.';

    public const SECTIONS = ['sound_adjustment', 'wired_speakers', 'bluetooth', 'device_info', 'network_settings', 'wireless_speakers', 'source_activation', 'sleep_timer', 'digits'];

    public Device $device;

    public bool $ready = false;

    /** @var string[] */
    public array $capabilities = [];

    public ?array $sound = null;

    public ?array $bluetooth = null;

    public ?array $info = null;

    public ?array $sleepTimer = null;

    /** @var array<int, array{source_id: string, friendly_name: string, in_use: bool, shared_from: ?string}> */
    public array $inputs = [];

    public string $defaultSource = '';

    public string $name = '';

    public ?array $network = null;

    public ?array $wirelessSpeakers = null;

    public ?array $wiredSpeakers = null;

    public bool $wiredDhcp = true;

    /** @var array<string, string> */
    public array $wired = ['address' => '', 'subnet_mask' => '', 'gateway' => '', 'preferred_dns' => '', 'alternative_dns' => ''];

    public string $wifiSsid = '';

    public string $wifiPassphrase = '';

    public string $wifiSecurity = '';

    /** @var array<string, string> section => error message */
    public array $problems = [];

    /** @var array<string, string> section => confirmation message */
    public array $notices = [];

    public function mount(Device $device): void
    {
        $this->device = $device;
        $this->capabilities = array_values(array_intersect(self::SECTIONS, DeviceCapabilities::for($device)));
    }

    public function load(): void
    {
        $this->ready = true;

        foreach (array_diff($this->capabilities, ['digits']) as $section) {
            $this->read($section);
        }
    }

    public function setSound(string $part, int|string $value): void
    {
        $this->run('sound_adjustment', fn (SoundAdjustmentInterface $driver) => $driver->setSoundAdjustment(
            bass: $part === 'bass' ? (int) $value : null,
            treble: $part === 'treble' ? (int) $value : null,
        ));
    }

    public function setLoudness(bool $on): void
    {
        $this->run('sound_adjustment', fn (SoundAdjustmentInterface $driver) => $driver->setSoundAdjustment(loudness: $on));
    }

    public function setSleepTimer(int|string $minutes): void
    {
        $this->run('sleep_timer', fn (SleepTimerInterface $driver) => $driver->setSleepTimer((int) $minutes),
            (int) $minutes === 0 ? 'Sleep timer off.' : "Going to standby in {$minutes} minutes.");
    }

    /** One number key on the active TV / set-top box source; nothing to read back. */
    public function pressDigit(int|string $digit): void
    {
        $this->run('digits', fn (DigitsInterface $driver) => $driver->sendDigit((int) $digit), reread: false);
    }

    public function setDiscoverable(bool $on): void
    {
        $this->run('bluetooth', fn (BluetoothInterface $driver) => $driver->setBluetooth(discoverable: $on),
            $on ? 'Discoverable. Pair from the phone now.' : 'No longer discoverable.');
    }

    public function setReconnectMode(string $mode): void
    {
        $this->run('bluetooth', fn (BluetoothInterface $driver) => $driver->setBluetooth(reconnectMode: $mode));
    }

    public function removeBluetoothDevice(string $id): void
    {
        $this->run('bluetooth', fn (BluetoothInterface $driver) => $driver->removeBluetoothDevice($id), 'Removed.');
    }

    public function reload(string $section): void
    {
        abort_unless(auth()->check(), 403);
        abort_unless(in_array($section, $this->capabilities, true), 404);

        $this->read($section);
    }

    public function setInterface(string $interface): void
    {
        $this->run('network_settings', fn (NetworkSettingsInterface $driver) => $driver->setActiveInterface($interface), self::NETWORK_APPLIED, reread: false);
    }

    public function saveWired(): void
    {
        $this->run('network_settings', fn (NetworkSettingsInterface $driver) => $driver->setWiredAddress($this->wiredDhcp, $this->wired), self::NETWORK_APPLIED, reread: false);
    }

    public function joinWifi(): void
    {
        $this->run('network_settings', function (NetworkSettingsInterface $driver) {
            $driver->joinWifi(trim($this->wifiSsid), $this->wifiPassphrase ?: null, trim($this->wifiSecurity) ?: null);
            $this->wifiPassphrase = '';
        }, self::NETWORK_APPLIED, reread: false);
    }

    public function setWiredSpeakerType(string $id, string $type): void
    {
        $this->run('wired_speakers', fn (WiredSpeakersInterface $driver) => $driver->setWiredSpeaker($id, type: $type), 'Saved.');
    }

    /** A test noise left on (by the device's own UI) can be stopped here; starting one is not offered. */
    public function stopWiredSpeakerNoise(string $id): void
    {
        $this->run('wired_speakers', fn (WiredSpeakersInterface $driver) => $driver->setWiredSpeaker($id, sound: 'none'), 'Test noise stopped.');
    }

    public function startScan(): void
    {
        $this->run('wireless_speakers', fn (WirelessSpeakersInterface $driver) => $driver->startWirelessScan());
    }

    public function stopScan(): void
    {
        $this->run('wireless_speakers', fn (WirelessSpeakersInterface $driver) => $driver->stopWirelessScan());
    }

    /** The input this device is put on when it wakes without anything playing; '' for none. */
    public function setDefaultSource(string $sourceId): void
    {
        $this->run('source_activation', function () use ($sourceId) {
            $known = array_column($this->inputs, 'source_id');
            if ($sourceId !== '' && !in_array($sourceId, $known, true)) {
                throw new \InvalidArgumentException('That input does not exist on this device.');
            }

            if ($sourceId === '') {
                $this->device->meta()->where('key', SwitchToDefaultSource::META_KEY)->delete();
            } else {
                $this->device->meta()->updateOrCreate(['key' => SwitchToDefaultSource::META_KEY], ['value' => $sourceId]);
            }
        }, $sourceId === '' ? 'Default input cleared.' : 'Saved.');
    }

    public function switchInput(string $sourceId): void
    {
        $this->run('source_activation', fn (SourceActivationInterface $driver) => $driver->activateSource($sourceId), 'Switched.');
    }

    public function rename(): void
    {
        $name = trim($this->name);
        if ($name === '' || mb_strlen($name) > 64) {
            $this->problems['device_info'] = 'The name must be 1 to 64 characters.';

            return;
        }

        $this->run('device_info', function (DeviceInfoInterface $driver) use ($name) {
            $driver->setDeviceName($name);
            $this->device->update(['device_name' => $name]);
        }, 'Renamed.');
    }

    /** Runs a change on the driver, then re-reads the section so the page shows what the device says. */
    private function run(string $section, \Closure $change, ?string $notice = null, bool $reread = true): void
    {
        abort_unless(auth()->check(), 403);

        unset($this->problems[$section], $this->notices[$section]);

        try {
            $change($this->driver($section));
            if ($notice) {
                $this->notices[$section] = $notice;
            }
        } catch (\Throwable $e) {
            $this->problems[$section] = $e->getMessage();
        }

        // A network change can move the device to another address: leave it to the user to re-read.
        if ($reread) {
            $this->read($section, keepMessages: true);
        }
    }

    private function read(string $section, bool $keepMessages = false): void
    {
        if (!$keepMessages) {
            unset($this->problems[$section], $this->notices[$section]);
        }

        if ($this->device->state === State::Unreachable) {
            $this->problems[$section] = 'The device is not reachable.';

            return;
        }

        try {
            $driver = $this->driver($section);

            match ($section) {
                'sound_adjustment' => $this->sound = $driver->getSoundAdjustment()->toArray(),
                'bluetooth' => $this->bluetooth = $driver->getBluetooth()->toArray(),
                'device_info' => $this->fillInfo($driver->getDeviceInfo()->toArray()),
                'source_activation' => $this->fillInputs($driver->getSources()),
                'network_settings' => $this->fillNetwork($driver->getNetwork()->toArray()),
                'wireless_speakers' => $this->wirelessSpeakers = $driver->getWirelessSpeakers()->toArray(),
                'wired_speakers' => $this->wiredSpeakers = $driver->getWiredSpeakers()->toArray(),
                'sleep_timer' => $this->sleepTimer = $driver->getSleepTimer()->toArray(),
            };
        } catch (\Throwable $e) {
            $this->problems[$section] ??= 'Could not read this from the device: '.$e->getMessage();
        }
    }

    private function fillNetwork(array $network): void
    {
        $this->network = $network;

        $wired = $network['wired'] ?? [];
        $this->wiredDhcp = $wired['dhcp'] ?? true;
        $this->wired = [
            'address' => $wired['address'] ?? '',
            'subnet_mask' => $wired['subnet_mask'] ?? '',
            'gateway' => $wired['gateway'] ?? '',
            'preferred_dns' => $wired['preferred_dns'] ?? '',
            'alternative_dns' => $wired['alternative_dns'] ?? '',
        ];
    }

    /** @param  \App\Domain\Device\AvailableSource[]  $sources */
    private function fillInputs(array $sources): void
    {
        $this->inputs = array_map(fn ($s) => [
            'source_id' => $s->sourceId,
            'friendly_name' => $s->friendlyName,
            'in_use' => $s->inUse,
            'shared_from' => $s->borrowed ? ($s->providerName ?: 'another product') : null,
        ], $sources);
        $this->defaultSource = (string) $this->device->meta()->where('key', SwitchToDefaultSource::META_KEY)->value('value');
    }

    private function fillInfo(array $info): void
    {
        $this->info = $info;
        $this->name = $info['name'];
    }

    private function driver(string $section): object
    {
        $contract = Capabilities::contractFor($section) ?? throw new \RuntimeException('This device does not support that.');

        $driver = $this->device->driver;
        if (!($driver instanceof $contract)) {
            throw new \RuntimeException('This device does not support that.');
        }

        return $driver;
    }

    public function render()
    {
        return view('livewire.device-settings');
    }
}
