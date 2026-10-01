<?php

namespace App\Integrations\Contracts;

interface PowerInterface
{
    /**
     * Wake the device from standby. Throws UnsupportedOperationException
     * when the device can only be put into standby remotely.
     */
    public function powerOn(): void;

    /** Put the device into standby. */
    public function standby(): void;
}
