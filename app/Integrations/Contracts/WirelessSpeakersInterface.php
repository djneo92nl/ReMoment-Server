<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\Settings\WirelessSpeakersState;

/** Wireless (WiSA) speaker setup, for models that have the hardware (see HardwareFeatures). */
interface WirelessSpeakersInterface
{
    public function getWirelessSpeakers(): WirelessSpeakersState;

    public function startWirelessScan(): void;

    public function stopWirelessScan(): void;
}
