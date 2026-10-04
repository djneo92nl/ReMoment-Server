<?php

namespace App\Integrations\Sonos\Connectors;

use App\Domain\Device\Settings\AdjustmentRange;
use App\Domain\Device\Settings\DeviceInfo;
use App\Domain\Device\Settings\SoundAdjustment;
use App\Integrations\Common\UnsupportedOperationException;

/** Sound adjustment and device info for Sonos, through the speaker's rendering/device-properties services. */
trait SettingsControls
{
    private const SONOS_EQ_MIN = -10;

    private const SONOS_EQ_MAX = 10;

    abstract public function deviceApiClient(): object;

    // --- SoundAdjustmentInterface ---

    public function getSoundAdjustment(): SoundAdjustment
    {
        $speaker = $this->deviceApiClient();

        return new SoundAdjustment(
            new AdjustmentRange($speaker->getBass(), self::SONOS_EQ_MIN, self::SONOS_EQ_MAX),
            new AdjustmentRange($speaker->getTreble(), self::SONOS_EQ_MIN, self::SONOS_EQ_MAX),
            $speaker->getLoudness(),
        );
    }

    public function setSoundAdjustment(?int $bass = null, ?int $treble = null, ?bool $loudness = null): void
    {
        foreach (['bass' => $bass, 'treble' => $treble] as $part => $value) {
            if ($value !== null && ($value < self::SONOS_EQ_MIN || $value > self::SONOS_EQ_MAX)) {
                throw new \InvalidArgumentException("{$part} must be between ".self::SONOS_EQ_MIN.' and '.self::SONOS_EQ_MAX.' (step 1).');
            }
        }

        $speaker = $this->deviceApiClient();
        if ($bass !== null) {
            $speaker->setBass($bass);
        }
        if ($treble !== null) {
            $speaker->setTreble($treble);
        }
        if ($loudness !== null) {
            $speaker->setLoudness($loudness);
        }
    }

    // --- DeviceInfoInterface ---

    public function getDeviceInfo(): DeviceInfo
    {
        $speaker = $this->deviceApiClient();

        // Firmware and MAC are nice to have: a speaker that won't tell is not an error.
        try {
            $zone = $speaker->soap('DeviceProperties', 'GetZoneInfo')->getArray();
        } catch (\Throwable) {
            $zone = [];
        }

        return new DeviceInfo(
            $speaker->getRoom(),
            $this->device->device_product_type,
            $zone['DisplaySoftwareVersion'] ?? $zone['SoftwareVersion'] ?? null,
            $zone['MACAddress'] ?? null,
            renamable: false,
        );
    }

    public function setDeviceName(string $name): void
    {
        throw new UnsupportedOperationException('Sonos rooms are renamed in the Sonos app.');
    }
}
