<?php

namespace App\Integrations\Contracts;

use App\Domain\Device\RemoteKey;

/** A device with a remote control (a TV, a set-top box): single key presses, like the keys of its own remote. */
interface RemoteControlInterface
{
    /**
     * The keys this device accepts; the API answers 422 for any other.
     *
     * @return RemoteKey[]
     */
    public function supportedKeys(): array;

    /** Press one key. Throws UnsupportedOperationException for a key the device lacks, and InvalidArgumentException never (the enum is closed). */
    public function sendKey(RemoteKey $key): void;
}
