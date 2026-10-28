<?php

namespace App\Livewire;

use App\Domain\Availability\ExternalCatalogues;
use App\Domain\Availability\ExternalItem;
use App\Domain\Catalogue\WantedTracks;
use App\Livewire\Concerns\PlaysExternal;
use App\Livewire\Concerns\WantsStreamingTracks;
use Livewire\Component;

/**
 * One section of the Streaming page, read live from the service and loaded on its own (a slow or refused section never holds up
 * the others): saved albums, liked songs or recently played. Playable and wantable; nothing is stored, a track is never favorited.
 */
class StreamingSection extends Component
{
    use PlaysExternal, WantsStreamingTracks;

    public const TITLES = ['saved-albums' => 'Saved albums', 'liked-tracks' => 'Liked songs', 'recent' => 'Recently played'];

    public string $provider = 'spotify';

    /** saved-albums | liked-tracks | recent */
    public string $section = 'liked-tracks';

    public bool $loaded = false;

    /** Rows shown before "Show all". */
    public int $limit = 12;

    public function load(): void
    {
        $this->loaded = true;
    }

    public function showAll(): void
    {
        $this->limit = PHP_INT_MAX;
    }

    public function render()
    {
        $items = [];
        $error = null;

        if ($this->loaded && ($catalogue = ExternalCatalogues::for($this->provider))?->enabled()) {
            try {
                $items = $catalogue->account($this->section);
            } catch (\Throwable $e) {
                $error = $e->getCode() === 403
                    ? 'Spotify did not allow this. Reconnect Spotify under Settings to grant it.'
                    : 'Spotify could not be reached. Try again in a moment.';
            }
        }

        $wished = [];
        foreach ($items as $item) {
            if ($item->type === 'track') {
                $wished[$item->id] = WantedTracks::isWished($item->artist, $item->title);
            }
        }

        return view('livewire.streaming-section', [
            'items' => $items,
            'wished' => $wished,
            'error' => $error,
            'title' => self::TITLES[$this->section] ?? $this->section,
            'payload' => fn (ExternalItem $t) => ['title' => $t->title, 'artist' => $t->artist, 'album' => null, 'image' => $t->image],
        ]);
    }
}
