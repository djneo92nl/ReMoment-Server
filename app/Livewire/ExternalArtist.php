<?php

namespace App\Livewire;

use App\Domain\Availability\ExternalCatalogues;
use App\Livewire\Concerns\PlaysExternal;
use Livewire\Component;

/** An artist as an external service (Spotify) has it, including artists the library does not have: their albums, playable and addable. */
class ExternalArtist extends Component
{
    use PlaysExternal;

    public string $provider = 'spotify';

    public string $artistId = '';

    /** Albums shown before "Show all". */
    public int $limit = 24;

    /** @var list<string> album ids queued for import from here */
    public array $queued = [];

    public function showAll(): void
    {
        $this->limit = PHP_INT_MAX;
    }

    public function addAlbum(string $id): void
    {
        ExternalCatalogues::for($this->provider)?->add('album', $id);
        $this->queued[] = $id;
    }

    /** Adds every album the library lacks (queued). */
    public function addAll(): void
    {
        $queued = ExternalCatalogues::for($this->provider)?->add('artist', $this->artistId) ?? ['albums' => 0];
        $this->queued[] = $this->artistId;
        $this->notice = $queued['albums'] > 0 ? "Adding {$queued['albums']} ".($queued['albums'] === 1 ? 'album' : 'albums').' to the library.' : 'The library has all of these albums already.';
    }

    public function render()
    {
        $catalogue = ExternalCatalogues::for($this->provider);
        $found = null;
        $failed = false;

        if ($catalogue?->enabled()) {
            try {
                $found = $catalogue->artist($this->artistId);
            } catch (\Throwable) {
                $failed = true;
            }
        }

        return view('livewire.external-artist', [
            'found' => $found,
            'failed' => $failed,
            'connected' => (bool) $catalogue?->enabled(),
            'name' => ucfirst($this->provider),
        ]);
    }
}
