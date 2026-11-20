<?php

namespace Tests\Support;

use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\ReportsActiveSource;
use App\Models\Device;

/** A host with no playback contract of its own (a TV box), only the report of its selected source. */
class FakeInputHostDriver implements MusicPlayerDriverInterface, ReportsActiveSource
{
    public function __construct(public Device $device) {}
}
