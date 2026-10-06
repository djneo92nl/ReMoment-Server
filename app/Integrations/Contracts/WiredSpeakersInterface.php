<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\Settings\WiredSpeakersState;

/** Which speakers are connected to a device's wired outputs, for models that have them (see HardwareFeatures). */
interface WiredSpeakersInterface
{
    public function getWiredSpeakers(): WiredSpeakersState;

    /**
     * Change the speaker type and/or the test sound of an output (null = leave
     * as is). Throws InvalidArgumentException for an unknown output, type or sound.
     */
    public function setWiredSpeaker(string $id, ?string $type = null, ?string $sound = null): void;
}
