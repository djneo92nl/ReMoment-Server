<?php

namespace App\Livewire;

use App\Domain\Library\LibrarySources;
use App\Domain\Library\RelatedWorks;
use App\Models\Media\Track;
use Livewire\Component;

/**
 * "Related" row of a track's details (album page): covers, remixes, samples, arrangements and other versions from
 * MusicBrainz relationships. A related track the library holds is a link to its album, anything else plain text; the
 * other library tracks performing the same song are listed as "Other versions here". Domain: RelatedWorks;
 * docs/frontend/related-works.md.
 */
class TrackRelated extends Component
{
    public int $trackId;

    public function mount(int $trackId): void
    {
        $this->trackId = $trackId;
    }

    public function render()
    {
        $found = RelatedWorks::for(Track::findOrFail($this->trackId), LibrarySources::hiddenForRequest(null));

        return view('livewire.track-related', $found);
    }
}
