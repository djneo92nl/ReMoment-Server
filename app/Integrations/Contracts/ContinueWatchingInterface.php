<?php

namespace App\Integrations\Contracts;

use App\Domain\Media\MediaItem;

/** Capability `continue_watching`: what the server's user started and did not finish. */
interface ContinueWatchingInterface
{
    /** @return list<MediaItem> @throws \Throwable when the server does not answer */
    public function continueWatching(int $limit = 12): array;
}
