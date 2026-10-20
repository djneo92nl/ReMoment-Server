<?php

namespace App\Integrations\Contracts;

use App\Models\Media\Track;
use Illuminate\Support\Collection;

interface QueuePlayNextInterface
{
    /**
     * Puts tracks right after the current one, in the given order (same rules as QueueAddInterface).
     *
     * @param  Collection<int, Track>  $tracks
     */
    public function playNext(Collection $tracks): void;
}
