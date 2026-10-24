<?php

namespace App\Domain\Radio;

use App\Models\RadioStation;

/** The ICY metadata of the station's plain stream URL. */
class IcyProvider implements NowPlayingProvider
{
    public function __construct(private readonly IcyReader $reader) {}

    public function source(): string
    {
        return 'icy';
    }

    public function supports(RadioStation $station): bool
    {
        return $station->streamUrl() !== null;
    }

    public function title(RadioStation $station): ?string
    {
        return $this->reader->title($station->streamUrl());
    }
}
