<?php

namespace App\Domain\Radio;

use App\Models\RadioStation;

/** A way to learn what a station plays right now, whatever device (if any) listens: ICY today, NTS' API later. */
interface NowPlayingProvider
{
    /** Short name stored with the title: `icy`, `nts`, … */
    public function source(): string;

    public function supports(RadioStation $station): bool;

    /** "Artist - Title" (or a show title), null when the station has none or can't be reached. */
    public function title(RadioStation $station): ?string;
}
