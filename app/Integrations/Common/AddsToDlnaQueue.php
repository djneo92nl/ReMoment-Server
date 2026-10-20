<?php

namespace App\Integrations\Common;

use App\Models\Media\Track;
use Illuminate\Support\Collection;

/** QueueAddInterface for a player that appends a DLNA URL to its own queue (the `enqueueDlna` of PlaysDlnaLibrary). */
trait AddsToDlnaQueue
{
    /** Appends the stream to the queue. */
    abstract protected function enqueueDlna(string $url, ?Track $track = null): void;

    public function addToQueue(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \InvalidArgumentException('None of these tracks have a playable DLNA stream.');
        }

        foreach ($playable as $track) {
            $this->enqueueDlna($track->getDlnaUrl(), $track);
        }
    }
}
