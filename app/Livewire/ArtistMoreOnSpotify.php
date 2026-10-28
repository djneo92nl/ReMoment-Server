<?php

namespace App\Livewire;

use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyCatalog;
use App\Jobs\ImportSpotifyAlbumById;
use App\Livewire\Concerns\PlaysExternal;
use App\Models\Media\Artist;
use Livewire\Component;

/** The artist's Spotify albums that the library doesn't have, loaded lazily, each addable. */
class ArtistMoreOnSpotify extends Component
{
    use PlaysExternal;

    public Artist $artist;

    public bool $loaded = false;

    public bool $failed = false;

    /** Albums shown before "Show all". */
    public int $limit = 12;

    /** @var list<string> Spotify album ids queued for import from here */
    public array $queued = [];

    public function load(): void
    {
        $this->loaded = true;
    }

    public function showAll(): void
    {
        $this->limit = PHP_INT_MAX;
    }

    public function add(string $spotifyAlbumId): void
    {
        ImportSpotifyAlbumById::dispatch($spotifyAlbumId);
        $this->queued[] = $spotifyAlbumId;
    }

    /** Plays the album from Spotify on the pinned player, without adding it. */
    public function play(string $spotifyAlbumId): void
    {
        $this->playExternal('album', $spotifyAlbumId);
    }

    public function addAll(): void
    {
        foreach ($this->missing() as $album) {
            $this->add($album['id']);
        }
    }

    /** @return list<array> */
    private function missing(): array
    {
        $catalog = app(SpotifyCatalog::class);

        if (!$catalog->available()) {
            return [];
        }

        try {
            $have = $this->artist->albums()->pluck('name_key')->all();

            return array_values(array_filter(
                $catalog->artistAlbums($this->artist),
                fn ($album) => !in_array(Normalizer::album($album['name']), $have, true),
            ));
        } catch (\Throwable) {
            $this->failed = true;

            return [];
        }
    }

    public function render()
    {
        return view('livewire.artist-more-on-spotify', [
            'albums' => $albums = $this->loaded ? $this->missing() : [],
            'shown' => array_slice($albums, 0, $this->limit),
            'available' => app(SpotifyCatalog::class)->available(),
            'spotifyArtistId' => $this->artist->metadata()->where('key', 'spotify_id')->value('value'),
        ]);
    }
}
