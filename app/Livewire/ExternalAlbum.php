<?php

namespace App\Livewire;

use App\Domain\Availability\ExternalCatalogues;
use App\Livewire\Concerns\PlaysExternal;
use Livewire\Component;

/** An album as an external service (Spotify) has it: its tracks, each playable, the album and tracks addable to the library. */
class ExternalAlbum extends Component
{
    use PlaysExternal;

    public string $provider = 'spotify';

    public string $albumId = '';

    public bool $albumQueued = false;

    /** @var list<string> track ids queued for import from here */
    public array $queuedTracks = [];

    public function addAlbum(): void
    {
        ExternalCatalogues::for($this->provider)?->add('album', $this->albumId);
        $this->albumQueued = true;
    }

    public function addTrack(string $id): void
    {
        ExternalCatalogues::for($this->provider)?->add('track', $id);
        $this->queuedTracks[] = $id;
    }

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

        return view('livewire.external-album', [
            'found' => $found,
            'failed' => $failed,
            'connected' => (bool) $catalogue?->enabled(),
            'name' => ucfirst($this->provider),
        ]);
    }
}
