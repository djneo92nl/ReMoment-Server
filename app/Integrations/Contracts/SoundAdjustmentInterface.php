<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\Settings\SoundAdjustment;

interface SoundAdjustmentInterface
{
    public function getSoundAdjustment(): SoundAdjustment;

    /**
     * Change any of the parts (null = leave as is). Throws
     * InvalidArgumentException for a value outside the device's range and
     * UnsupportedOperationException for a part the device doesn't have.
     */
    public function setSoundAdjustment(?int $bass = null, ?int $treble = null, ?bool $loudness = null): void;
}
