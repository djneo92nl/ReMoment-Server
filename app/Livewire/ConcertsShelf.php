<?php

namespace App\Livewire;

use App\Domain\Concerts\Concerts;
use Livewire\Component;

/**
 * "Coming up" shelf of upcoming concerts (roadmap 12.6, docs/frontend/concerts.md): the artist page passes an `artistId`, the
 * Discover hub (or any page) uses it without one for every library artist. A small reusable piece: it renders nothing while
 * the setting is off or no concert matches the location, so a page can include it unconditionally. Reads the `concerts`
 * table only (no request to MusicBrainz).
 */
class ConcertsShelf extends Component
{
    public ?int $artistId = null;

    public int $limit = 12;

    public function render()
    {
        return view('livewire.concerts-shelf', Concerts::upcoming($this->artistId, null, $this->limit) + ['single' => $this->artistId !== null]);
    }
}
