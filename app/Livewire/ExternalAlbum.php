<?php

namespace App\Livewire;

use App\Domain\Availability\ExternalCatalogues;
use App\Domain\Catalogue\WantedTracks;
use App\Livewire\Concerns\PlaysExternal;
use App\Livewire\Concerns\WantsStreamingTracks;
use Livewire\Component;

/** An album as a streaming service has it: its tracks, each playable and wantable (wishlist); nothing is added to the library, and a track is never favorited. */
class ExternalAlbum extends Component
{
    use PlaysExternal, WantsStreamingTracks;

    public string $provider = 'spotify';

    public string $albumId = '';

    public function render()
    {
        $catalogue = ExternalCatalogues::for($this->provider);
        $found = null;
        $failed = false;

        if ($catalogue?->enabled()) {
            try {
                $found = $catalogue->album($this->albumId);
            } catch (\Throwable) {
                $failed = true;
            }
        }

        $wished = [];
        foreach ($found['tracks'] ?? [] as $track) {
            $wished[$track->id] = WantedTracks::isWished($track->artist ?? $found['album']->artist, $track->title);
        }

        return view('livewire.external-album', [
            'wished' => $wished,
            'found' => $found,
            'failed' => $failed,
            'connected' => (bool) $catalogue?->enabled(),
            'name' => ucfirst($this->provider),
        ]);
    }
}
