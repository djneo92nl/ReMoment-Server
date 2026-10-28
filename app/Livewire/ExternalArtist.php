<?php

namespace App\Livewire;

use App\Domain\Availability\ExternalCatalogues;
use App\Domain\Availability\ExternalItem;
use App\Domain\Catalogue\WantedTracks;
use App\Livewire\Concerns\PlaysExternal;
use App\Livewire\Concerns\WantsStreamingTracks;
use Livewire\Component;

/**
 * An artist as a streaming service has it: everything it offers (all albums and singles) and the popular tracks, like a local
 * artist page. Used as the Spotify tab of a library artist (`embedded`) and as the page of any artist the library lacks.
 * Tracks can be played and wanted (wishlist), never favorited; nothing is added to the library.
 */
class ExternalArtist extends Component
{
    use PlaysExternal, WantsStreamingTracks;

    public string $provider = 'spotify';

    public string $artistId = '';

    /** Inside a library artist's page: no header card of its own (the page's hero is above). */
    public bool $embedded = false;

    /** Albums shown before "Show all". */
    public int $limit = 24;

    public function showAll(): void
    {
        $this->limit = PHP_INT_MAX;
    }

    public function render()
    {
        $catalogue = ExternalCatalogues::for($this->provider);
        $found = null;
        $popular = [];
        $failed = false;

        if ($catalogue?->enabled()) {
            try {
                $found = $catalogue->artist($this->artistId);
                if ($found !== null) {
                    $popular = $catalogue->popularTracks($this->artistId, $found['artist']->title);
                }
            } catch (\Throwable) {
                $failed = true;
            }
        }

        $wished = [];
        foreach ($popular as $track) {
            $wished[$track->id] = WantedTracks::isWished($track->artist, $track->title);
        }

        return view('livewire.external-artist', [
            'found' => $found,
            'popular' => $popular,
            'wished' => $wished,
            'failed' => $failed,
            'connected' => (bool) $catalogue?->enabled(),
            'name' => ucfirst($this->provider),
            'payload' => fn (ExternalItem $t) => ['title' => $t->title, 'artist' => $t->artist, 'album' => null, 'image' => $t->image],
        ]);
    }
}
