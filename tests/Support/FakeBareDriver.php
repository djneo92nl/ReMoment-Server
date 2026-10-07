<?php

namespace Tests\Support;

use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Models\Device;

/** A driver with no optional capabilities, for asserting 422 "unsupported" responses. */
class FakeBareDriver implements MusicPlayerDriverInterface
{
    public function __construct(public Device $device) {}

}
