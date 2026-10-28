<?php

namespace App\Livewire;

use App\Domain\Availability\ExternalCatalogues;
use App\Domain\Catalogue\WantedTracks;
use App\Livewire\Concerns\PlaysExternal;
use App\Livewire\Concerns\WantsStreamingTracks;
use Livewire\Component;

/** A playlist of a streaming service as it is there: its tracks playable and wantable; never imported, never favorited. */
class ExternalPlaylist extends Component
{
    use PlaysExternal, WantsStreamingTracks;

    public string $provider = 'spotify';

    public string $playlistId = '';

    public function render()
    {
        $catalogue = ExternalCatalogues::for($this->provider);
        $found = null;
        $failed = false;

        if ($catalogue?->enabled()) {
            try {
                $found = $catalogue->playlist($this->playlistId);
            } catch (\Throwable) {
                $failed = true;
            }
        }

        $wished = [];
        foreach ($found['tracks'] ?? [] as $track) {
            $wished[$track->id] = WantedTracks::isWished($track->artist, $track->title);
        }

        return view('livewire.external-playlist', [
            'found' => $found,
            'wished' => $wished,
            'failed' => $failed,
            'connected' => (bool) $catalogue?->enabled(),
            'name' => ucfirst($this->provider),
        ]);
    }
}
