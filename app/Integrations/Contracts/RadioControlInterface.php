<?php

namespace App\Integrations\Contracts;

use App\Models\RadioStation;

interface RadioControlInterface
{
    public function radioPlatform(): string;

    public function canPlayRadioStation(RadioStation $station): bool;

    public function playRadioStation(RadioStation $station): void;
}
