<?php

namespace App\Integrations\Contracts;

use App\Domain\Media\MediaItem;

/** Capability `next_up`: the next unwatched episode of each series being followed. */
interface NextUpInterface
{
    /** @return list<MediaItem> @throws \Throwable when the server does not answer */
    public function nextUp(int $limit = 12): array;
}
