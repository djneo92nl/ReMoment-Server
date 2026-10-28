<?php

namespace App\Livewire;

use App\Domain\Catalogue\CatalogueItems;
use App\Domain\Catalogue\SpotifyAvailability;
use App\Domain\Catalogue\WantedTracks;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\SpotifyUri;
use App\Jobs\ImportSpotifyAlbumById;
use App\Jobs\LookupSpotifyAvailability;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\WantedTrack;
use App\Services\SpotifyTokenService;
use Livewire\Component;

/**
 * The MusicBrainz discography of an artist (what exists, what the library has, what is only on a service) or, without
 * an artist, the household wishlist. Wish, "not interested" and add-to-library are the same actions as the API's
 * (docs/api/availability.md); the lists come from CatalogueItems.
 */
class Catalogue extends Component
{
    /** Set on an artist page; null shows the wishlist. */
    public ?int $artistId = null;

    /** all | missing | wanted */
    public string $filter = 'all';

    public bool $showHidden = false;

    /** Entries shown before "Show all". */
    public int $limit = 12;

    /** @var list<int> catalogue entries queued for import from here */
    public array $queued = [];

    public function mount(): void
    {
        if ($artist = $this->artist()) {
            LookupSpotifyAvailability::queueFor($artist);
        }
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'missing', 'wanted'], true) ? $filter : 'all';
    }

    public function showAll(): void
    {
        $this->limit = PHP_INT_MAX;
    }

    public function want(int $id, bool $wanted = true): void
    {
        if ($row = CatalogueAlbum::find($id)) {
            $row->wanted_at = $wanted ? ($row->wanted_at ?? now()) : null;
            $row->save();
        }
    }

    public function ignore(int $id, bool $ignored = true): void
    {
        if ($row = CatalogueAlbum::find($id)) {
            $row->ignored_at = $ignored ? ($row->ignored_at ?? now()) : null;
            $row->save();
        }
    }

    public function removeSong(int $id): void
    {
        if ($song = WantedTrack::find($id)) {
            WantedTracks::unwish($song);
        }
    }

    public function add(int $id): void
    {
        $row = CatalogueAlbum::find($id);
        $answer = $row?->availability()->where('provider', SpotifyAvailability::PROVIDER)->where('status', 'available')->first();
        $spotifyId = SpotifyUri::albumId($answer?->external_id);

        if ($row !== null && $row->album_id === null && $spotifyId !== null && app(SpotifyTokenService::class)->isConnected()) {
            ImportSpotifyAlbumById::dispatch($spotifyId);
            $this->queued[] = $id;
        }
    }

    private function artist(): ?Artist
    {
        return $this->artistId ? Artist::find($this->artistId) : null;
    }

    public function render()
    {
        $artist = $this->artist();

        $items = $artist
            ? CatalogueItems::forArtist($artist, $this->filter === 'missing' ? 'missing' : null, $this->filter === 'wanted', $this->showHidden)
            : CatalogueItems::wishlist(0)[1];

        $everything = $artist ? CatalogueItems::forArtist($artist, ignored: false) : $items;

        // Where each library plays: the visible devices that can play it (shown as "Plays on Kitchen, Study").
        $devices = Device::where('hidden', false)->orderBy('device_name')->get();
        $playsOn = [];
        foreach (array_unique(array_filter(array_merge(...array_map(fn ($i) => array_column($i['availability'], 'library'), $items) ?: [[]]))) as $library) {
            $playsOn[$library] = $devices->filter(fn (Device $d) => LibrarySources::playableOn($library, $d))->pluck('device_name')->all();
        }

        $spotify = app(SpotifyAvailability::class);

        return view('livewire.catalogue', [
            'items' => $items,
            'shown' => array_slice($items, 0, $this->limit),
            'counts' => ['all' => count($everything), 'missing' => count(array_filter($everything, fn ($i) => $i['album_id'] === null)), 'wanted' => count(array_filter($everything, fn ($i) => $i['wanted']))],
            'playsOn' => $playsOn,
            'hasRows' => $artist !== null && CatalogueAlbum::where('artist_id', $artist->id)->exists(),
            'canImport' => app(SpotifyTokenService::class)->isConnected(),
            'songs' => $artist === null ? WantedTracks::wishlist() : [],
            'pending' => $artist !== null && $spotify->enabled() && $spotify->staleRows($artist)->isNotEmpty(),
            'artist' => $artist,
        ]);
    }
}
