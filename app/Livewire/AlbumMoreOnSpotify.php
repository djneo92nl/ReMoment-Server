<?php

namespace App\Livewire;

use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyCatalog;
use App\Livewire\Concerns\WantsStreamingTracks;
use App\Models\Media\Album;
use App\Support\SelectedDevice;
use Livewire\Component;

/**
 * The album as Spotify has it (the Spotify side of the album page's source switch), in album order:
 * the tracks the library has are marked, the others can be added in one go. Loaded lazily. Loading also records the
 * position of the tracks we have (`track_number`), so the album page orders and numbers them.
 */
class AlbumMoreOnSpotify extends Component
{
    use WantsStreamingTracks;

    public Album $album;

    public bool $loaded = false;

    public bool $failed = false;

    public ?string $message = null;

    public function load(): void
    {
        $this->loaded = true;
    }

    /**
     * Spotify's tracks in order, each flagged with the library track that has it (if any);
     * null when Spotify doesn't have the album.
     *
     * @return list<array>|null
     */
    private function rows(): ?array
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

        $mine = $this->album->tracks()->with(['metadata' => fn ($q) => $q->whereIn('key', ['track_number', 'dlna_url', 'external_id'])])->get()->keyBy('name_key');

        usort($tracks, fn ($a, $b) => [$a['disc'] ?? 1, $a['number']] <=> [$b['disc'] ?? 1, $b['number']]);

        return array_map(fn ($t) => $t + ['local' => $mine[Normalizer::track($t['name'])] ?? null], $tracks);
    }

    public function render()
    {
        $available = app(SpotifyCatalog::class)->available();
        $rows = $available && $this->loaded ? $this->rows() : [];
        $pinned = app(SelectedDevice::class)->device();

        return view('livewire.album-more-on-spotify', [
            'available' => $available,
            'rows' => $rows,
            'pinned' => $pinned,
            // A play button only where the pinned player can play Spotify; without a pinned player it opens the player panel.
            'canPlay' => $pinned === null || LibraryPlayback::canPlaySpotify($pinned),
            'wished' => collect(is_array($rows) ? $rows : [])->mapWithKeys(fn ($r) => [$r['id'] => \App\Domain\Catalogue\WantedTracks::isWished($this->album->artist?->name, $r['name'])])->all(),
        ]);
    }
}
