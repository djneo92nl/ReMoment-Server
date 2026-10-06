<?php

namespace App\Livewire;

use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyCatalog;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Models\Media\Album;
use Livewire\Component;

/** Tracks of the album's Spotify release that the library's copy lacks, loaded lazily, addable in one go. */
class AlbumMoreOnSpotify extends Component
{
    public Album $album;

    public bool $loaded = false;

    public bool $failed = false;

    public ?string $message = null;

    public function load(): void
    {
        $this->loaded = true;
    }

    public function addAll(SpotifyLibraryImporter $importer): void
    {
        try {
            $spotifyAlbumId = $importer->findAlbumId($this->album);

            if ($spotifyAlbumId === null) {
                $this->message = 'Could not find this album on Spotify.';

                return;
            }

            $before = $this->album->tracks()->count();
            $importer->importAlbum($spotifyAlbumId, $this->album);
            $added = $this->album->tracks()->count() - $before;
            $this->message = $added > 0 ? "Added {$added} ".str('track')->plural($added).' from Spotify.' : 'Nothing to add.';
        } catch (\Throwable $e) {
            $this->message = "Could not add from Spotify: {$e->getMessage()}";
        }

        $this->dispatch('$refresh');
    }

    /** @return list<array>|null null when Spotify doesn't have the album */
    private function missing(): ?array
    {
        try {
            $tracks = app(SpotifyCatalog::class)->albumTracks($this->album);
        } catch (\Throwable) {
            $this->failed = true;

            return [];
        }

        if ($tracks === null) {
            return null;
        }

        $have = $this->album->tracks()->pluck('name_key')->all();

        return array_values(array_filter($tracks, fn ($t) => !in_array(Normalizer::track($t['name']), $have, true)));
    }

    public function render()
    {
        $available = app(SpotifyCatalog::class)->available();

        return view('livewire.album-more-on-spotify', [
            'available' => $available,
            'tracks' => $available && $this->loaded ? $this->missing() : [],
        ]);
    }
}
