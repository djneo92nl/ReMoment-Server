<?php

namespace App\Integrations\Contracts;

use App\Models\Media\Track;
use Illuminate\Support\Collection;

interface QueueAddInterface
{
    /**
     * Appends tracks to the end of the queue. A driver takes the tracks it can play (a DLNA stream, a
     * Spotify URI) in order and skips the rest; none playable is an \InvalidArgumentException.
     *
     * @param  Collection<int, Track>  $tracks
     */
    public function addToQueue(Collection $tracks): void;
}
