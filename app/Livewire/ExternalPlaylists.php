<?php

namespace App\Livewire;

use App\Domain\Availability\ExternalCatalogues;
use App\Livewire\Concerns\PlaysExternal;
use Livewire\Component;

/** The account's playlists on a streaming service, read live (the "Streaming" side of the playlists page); nothing is imported. */
class ExternalPlaylists extends Component
{
    use PlaysExternal;

    public string $provider = 'spotify';

    public function render()
    {
        $catalogue = ExternalCatalogues::for($this->provider);
        $playlists = [];
        $failed = false;

        if ($catalogue?->enabled()) {
            try {
                $playlists = $catalogue->playlists();
            } catch (\Throwable) {
                $failed = true;
            }
        }

        return view('livewire.external-playlists', [
            'playlists' => $playlists,
            'failed' => $failed,
            'connected' => (bool) $catalogue?->enabled(),
            'name' => ucfirst($this->provider),
        ]);
    }
}
