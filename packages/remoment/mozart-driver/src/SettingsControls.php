<?php

namespace Remoment\MozartDriver;

use App\Domain\Device\Settings\AdjustmentRange;
use App\Domain\Device\Settings\BluetoothDevice;
use App\Domain\Device\Settings\BluetoothState;
use App\Domain\Device\Settings\DeviceInfo;
use App\Domain\Device\Settings\SoundAdjustment;
use App\Integrations\Common\UnsupportedOperationException;

/**
 * Sound adjustment, Bluetooth and device info for Mozart. The client turns
 * "no answer" into null, which is never read as an empty result here.
 */
trait SettingsControls
{
    // --- SoundAdjustmentInterface ---

    public function getSoundAdjustment(): SoundAdjustment
    {
        $adjustments = $this->client->sound()->getAdjustments() ?? throw new \RuntimeException('The device did not answer.');
        $features = $this->client->sound()->getFeatures() ?? throw new \RuntimeException('The device did not report its sound features.');

        $range = function (string $part) use ($adjustments, $features) {
            $values = $this->featureValues($features, $part);

            return isset($adjustments[$part]) && $values
                ? new AdjustmentRange((int) $adjustments[$part], min($values), max($values), $this->stepOf($values))
                : null;
        };

        return new SoundAdjustment(
            $range('bass'),
            $range('treble'),
            isset($adjustments['loudness']) ? (bool) $adjustments['loudness'] : null,
        );
    }

    public function setSoundAdjustment(?int $bass = null, ?int $treble = null, ?bool $loudness = null): void
    {
        $current = $this->getSoundAdjustment();

        foreach (['bass' => $bass, 'treble' => $treble] as $part => $value) {
            if ($value === null) {
                continue;
            }
            $range = $current->{$part} ?? throw new UnsupportedOperationException("This device has no {$part} adjustment.");
            if (!$range->accepts($value)) {
                throw new \InvalidArgumentException("{$part} must be between {$range->min} and {$range->max} (step {$range->step}).");
            }
        }
        if ($loudness !== null && $current->loudness === null) {
            throw new UnsupportedOperationException('This device has no loudness adjustment.');
        }

        $sound = $this->client->sound();
        if ($bass !== null) {
            $sound->setBass($bass);
        }
        if ($treble !== null) {
            $sound->setTreble($treble);
        }
        if ($loudness !== null) {
            $sound->setLoudness($loudness);
        }
    }

    /**
     * The allowed values of one feature, from the first role that has it
     * (a product reports one feature set per role: standalone, multichannel, …).
     *
     * @return int[]
     */
    private function featureValues(array $features, string $part): array
    {
        foreach ($features as $featureSet) {
            $values = array_map(fn ($entry) => (int) ($entry['value'] ?? 0), $featureSet[$part]['range'] ?? []);
            if ($values) {
                return $values;
            }
        }

        return [];
    }

    /** The step of a list of allowed values: the greatest common difference, 1 for a plain min/max pair. */
    private function stepOf(array $values): int
    {
        sort($values);
        $step = 0;
        for ($i = 1; $i < count($values); $i++) {
            $step = $this->gcd($step, $values[$i] - $values[$i - 1]);
        }

        return count($values) > 2 && $step > 0 ? $step : 1;
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }

    // --- BluetoothInterface (list only: the spec has no pairing) ---

    public function getBluetooth(): BluetoothState
    {
        $list = $this->client->bluetooth()->getDevices() ?? throw new \RuntimeException('The device did not answer.');

        return new BluetoothState(
            true,
            false,
            null,
            [],
            false,
            array_map(
                fn (array $device) => new BluetoothDevice((string) ($device['address'] ?? ''), (string) ($device['name'] ?? $device['address'] ?? ''), $device['address'] ?? null, (bool) ($device['connected'] ?? false)),
                $list['items'] ?? []
            ),
        );
    }

    public function setBluetooth(?bool $discoverable = null, ?string $reconnectMode = null): void
    {
        throw new UnsupportedOperationException('This device only lists its Bluetooth devices.');
    }

    public function removeBluetoothDevice(string $id): void
    {
        throw new UnsupportedOperationException('This device only lists its Bluetooth devices.');
    }

    // --- DeviceInfoInterface ---

    public function getDeviceInfo(): DeviceInfo
    {
        $update = $this->client->product()->getSoftwareUpdate() ?? throw new \RuntimeException('The device did not answer.');

        // The API can set the name but not read it back, so the name is the one ReMoment has.
        return new DeviceInfo(
            (string) $this->device->device_name,
            $this->device->device_product_type,
            $update['softwareVersion'] ?? null,
        );
    }

    public function setDeviceName(string $name): void
    {
        $this->client->product()->setFriendlyName($name);
    }
}
