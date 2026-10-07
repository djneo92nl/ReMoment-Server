<?php

namespace Tests\Support;

use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Models\Device;
use App\Models\RadioStation;

/** A radio-capable driver whose platform is the `fakeradio` station meta key. */
class FakeRadioDriver implements MusicPlayerDriverInterface, RadioControlInterface
{
    public function __construct(public Device $device) {}

    public function radioPlatform(): string
    {
        return 'fakeradio';
    }

    public function canPlayRadioStation(RadioStation $station): bool
    {
        return $station->getMeta('fakeradio') !== null;
    }

    public function playRadioStation(RadioStation $station): void {}
}
