<?php

namespace Tests\Support;

use App\Domain\Device\Settings\AdjustmentRange;
use App\Domain\Device\Settings\BluetoothDevice;
use App\Domain\Device\Settings\BluetoothState;
use App\Domain\Device\Settings\DeviceInfo;
use App\Domain\Device\Settings\SoundAdjustment;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\BluetoothInterface;
use App\Integrations\Contracts\DeviceInfoInterface;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\SoundAdjustmentInterface;
use App\Models\Device;

/** Bound as a device's `device_driver` in settings API tests. */
class FakeSettingsDriver implements BluetoothInterface, DeviceInfoInterface, MusicPlayerDriverInterface, SoundAdjustmentInterface
{
    /** @var array<int, array> */
    public static array $calls = [];

    public static ?\Exception $throw = null;

    public static int $bass = 1;

    public static int $treble = 0;

    public static bool $loudness = true;

    public static bool $discoverable = false;

    public static string $reconnect = 'manual';

    public static bool $writable = true;

    public static string $name = 'Living Room';

    public static bool $renamable = true;

    /** @var BluetoothDevice[] */
    public static array $paired = [];

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$calls = [];
        self::$throw = null;
        self::$bass = 1;
        self::$treble = 0;
        self::$loudness = true;
        self::$discoverable = false;
        self::$reconnect = 'manual';
        self::$writable = true;
        self::$name = 'Living Room';
        self::$renamable = true;
        self::$paired = [new BluetoothDevice('phone-1', 'Remko iPhone', 'AA:BB:CC:DD:EE:FF', true)];
    }

    public function getSoundAdjustment(): SoundAdjustment
    {
        $this->failIfAsked();

        return new SoundAdjustment(new AdjustmentRange(self::$bass, -10, 10), new AdjustmentRange(self::$treble, -10, 10), self::$loudness);
    }

    public function setSoundAdjustment(?int $bass = null, ?int $treble = null, ?bool $loudness = null): void
    {
        $this->failIfAsked();
        foreach (['bass' => $bass, 'treble' => $treble] as $part => $value) {
            if ($value !== null && ($value < -10 || $value > 10)) {
                throw new \InvalidArgumentException("{$part} must be between -10 and 10 (step 1).");
            }
        }

        self::$calls[] = ['setSoundAdjustment', $bass, $treble, $loudness];
        self::$bass = $bass ?? self::$bass;
        self::$treble = $treble ?? self::$treble;
        self::$loudness = $loudness ?? self::$loudness;
    }

    public function getBluetooth(): BluetoothState
    {
        $this->failIfAsked();

        return new BluetoothState(true, self::$discoverable, self::$reconnect, self::$writable ? ['manual', 'automatic', 'disabled'] : [], self::$writable, self::$paired);
    }

    public function setBluetooth(?bool $discoverable = null, ?string $reconnectMode = null): void
    {
        $this->failIfAsked();
        if (!self::$writable) {
            throw new UnsupportedOperationException('This device only lists paired Bluetooth devices.');
        }
        if ($reconnectMode !== null && !in_array($reconnectMode, ['manual', 'automatic', 'disabled'], true)) {
            throw new \InvalidArgumentException("Unknown reconnect mode '{$reconnectMode}'.");
        }

        self::$calls[] = ['setBluetooth', $discoverable, $reconnectMode];
        self::$discoverable = $discoverable ?? self::$discoverable;
        self::$reconnect = $reconnectMode ?? self::$reconnect;
    }

    public function removeBluetoothDevice(string $id): void
    {
        $this->failIfAsked();
        if (!collect(self::$paired)->contains(fn (BluetoothDevice $d) => $d->id === $id)) {
            throw new \InvalidArgumentException('That Bluetooth device is not paired.');
        }

        self::$calls[] = ['removeBluetoothDevice', $id];
        self::$paired = array_values(array_filter(self::$paired, fn (BluetoothDevice $d) => $d->id !== $id));
    }

    public function getDeviceInfo(): DeviceInfo
    {
        $this->failIfAsked();

        return new DeviceInfo(self::$name, 'FAKE_SPEAKER', '1.0', 'AA:AA:AA:AA:AA:AA', self::$renamable);
    }

    public function setDeviceName(string $name): void
    {
        $this->failIfAsked();
        if (!self::$renamable) {
            throw new UnsupportedOperationException('This device cannot be renamed here.');
        }
        self::$calls[] = ['setDeviceName', $name];
        self::$name = $name;
    }

    private function failIfAsked(): void
    {
        if (self::$throw) {
            throw self::$throw;
        }
    }
}
