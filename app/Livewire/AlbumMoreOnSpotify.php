<?php

namespace App\Livewire;

use App\Domain\Library\Enrichment;
use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyCatalog;
use App\Domain\Library\SpotifyUri;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Models\Device;
use App\Models\Media\Album;
use Livewire\Component;

/**
 * The album's Spotify tracklist in album order: the tracks the library has are greyed out in
 * their place, the others can be added in one go. Loaded lazily. Loading also records the
 * position of the tracks we have (`track_number`), so the album page orders and numbers them.
 */
class AlbumMoreOnSpotify extends Component
{
    public Album $album;

    public bool $loaded = false;

    public bool $failed = false;

    public ?string $message = null;

    public function load(): void
    {
        $this->loaded = true;

        $rows = $this->rows();

        if (is_array($rows) && $this->recordPositions($rows) > 0) {
            // The album page above was rendered without the numbers.
            $this->redirect(route('albums.show', $this->album));
        }
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

    /**
     * Saves the Spotify position, and the Spotify id when the track has none (so it can be played), on
     * library tracks that lack them; returns how many tracks changed.
     */
    private function recordPositions(array $rows): int
    {
        $changed = 0;

        foreach ($rows as $row) {
            $track = $row['local'];

            if ($track === null) {
                continue;
            }

            $touched = false;

            if ($row['number'] > 0 && $track->metaValue('track_number') === null) {
                Enrichment::save($track, 'track_number', (string) $row['number'], 'int', Enrichment::SPOTIFY);
                $touched = true;
            }

            // Only when no other track already holds this id (a duplicate elsewhere in the library).
            $uri = SpotifyUri::track($row['id']);
            if (LibraryPlayback::spotifyUri($track) === null && LibraryIdentity::findByExternalId($uri, 'spotify') === null) {
                LibraryIdentity::addExternalId($track, $uri, 'spotify');
                $track->save();
                $touched = true;
            }

            $changed += $touched ? 1 : 0;
        }

        return $changed;
    }

    public function render()
    {
        $available = app(SpotifyCatalog::class)->available();
        $rows = $available && $this->loaded ? $this->rows() : [];

        return view('livewire.album-more-on-spotify', [
            'available' => $available,
            'rows' => $rows,
            'devices' => Device::libraryCapable(),
            'missing' => is_array($rows) ? count(array_filter($rows, fn ($r) => $r['local'] === null)) : 0,
        ]);
    }
}
