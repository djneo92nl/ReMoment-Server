<?php

namespace App\Livewire;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryPicks;
use App\Domain\Library\LibrarySources;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * Reusable library picker: a browse list (tabs artists, albums, tracks, playlists; an artist opens its albums, an album its
 * tracks, a playlist its tracks) with a detail card of the focused item and the basket of what is picked.
 *
 * Use it from any tool that needs a selection from the library:
 *
 *   <livewire:library-picker wire:model="picks" confirm-label="Export" :types="['artist','album','track','playlist']" />
 *
 * `picks` is a list of `{type, id}` (`Domain\Library\LibraryPicks` turns it into rows and track ids). The button of the
 * basket dispatches `library-picked` with `{name, picks, trackIds}` for the host to take; `name` is the `name` prop.
 */
class LibraryPicker extends Component
{
    private const PAGE = 40;

    /** @var list<array{type: string, id: int}> */
    #[Modelable]
    public array $picks = [];

    /** Tabs to offer, in order. @var list<string> */
    public array $types = LibraryPicks::TYPES;

    public string $confirmLabel = 'Use selection';

    /** Echoed in the `library-picked` event so a host with several pickers can tell them apart. */
    public string $name = 'library';

    /** Show only what has an own audio file on this server (export, download): no DLNA or streaming-only items. */
    public bool $ownFilesOnly = false;

    public string $tab = '';

    public string $search = '';

    public int $limit = self::PAGE;

    /** @var array<string, bool> expanded rows by key */
    public array $open = [];

    /** @var array{type: string, id: int}|null */
    public ?array $focus = null;

    public function mount(): void
    {
        $this->types = array_values(array_intersect($this->types, LibraryPicks::TYPES)) ?: LibraryPicks::TYPES;
        $this->tab = $this->tab !== '' && in_array($this->tab, $this->types, true) ? $this->tab : $this->types[0];
        $this->picks = LibraryPicks::normalize($this->picks);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, $this->types, true)) {
            $this->tab = $tab;
            $this->limit = self::PAGE;
            $this->open = [];
        }
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE;
    }

    public function more(): void
    {
        $this->limit += self::PAGE;
    }

    /** Click on a row: show its details and open or close what is under it. */
    public function focusItem(string $type, int $id): void
    {
        if (!in_array($type, LibraryPicks::TYPES, true)) {
            return;
        }
        $this->focus = ['type' => $type, 'id' => $id];
        $key = $type.':'.$id;
        if ($type !== 'track') {
            $this->open[$key] = !($this->open[$key] ?? false);
        }
    }

    public function toggle(string $type, int $id): void
    {
        $key = $type.':'.$id;
        $has = collect($this->picks)->contains(fn ($p) => $key === $p['type'].':'.$p['id']);

        $this->picks = $has
            ? array_values(array_filter($this->picks, fn ($p) => $key !== $p['type'].':'.$p['id']))
            : LibraryPicks::normalize([...$this->picks, ['type' => $type, 'id' => $id]]);
    }

    public function remove(string $type, int $id): void
    {
        $key = $type.':'.$id;
        $this->picks = array_values(array_filter($this->picks, fn ($p) => $key !== $p['type'].':'.$p['id']));
    }

    public function clear(): void
    {
        $this->picks = [];
    }

    public function confirm(): void
    {
        $picks = LibraryPicks::normalize($this->picks);
        if ($picks === []) {
            return;
        }

        $this->dispatch('library-picked', name: $this->name, picks: $picks, trackIds: LibraryPicks::trackIds($picks));
    }

    public function render(): View
    {
        $hidden = LeadingSource::hidden(LeadingSource::defaultScope());
        $rows = $this->rows($hidden);
        $picked = LibraryPicks::describe($this->picks, $hidden, $this->ownFilesOnly);

        return view('livewire.library-picker', [
            'rows' => $rows['rows'],
            'hasMore' => $rows['more'],
            'pickedKeys' => array_flip(array_map(fn ($p) => $p['type'].':'.$p['id'], $this->picks)),
            'picked' => $picked,
            'detail' => $this->focus ? $this->detail($this->focus, $hidden) : null,
            'total' => array_sum(array_column($picked, 'tracks')),
            'megabytes' => (int) round(array_sum(array_column($picked, 'tracks')) * LibraryPicks::AVERAGE_MB),
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, more: bool} */
    private function rows(array $hidden): array
    {
        $rows = [];
        $more = false;
        $like = $this->search !== '' ? '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($this->search)).'%' : null;

        if ($this->tab === 'artist') {
            $artists = LibrarySources::artists(Artist::query()->whereHas('albums', fn ($a) => $this->withFiles($a))->withCount(['albums' => fn ($a) => $this->withFiles($a)]), $hidden)
                ->when($like, fn ($q) => $q->whereRaw('LOWER(artists.name) LIKE ?', [$like]))
                ->orderByRaw(Artist::SORT_NAME_SQL)->orderBy('artists.id')->limit($this->limit + 1)->get();
            $more = $artists->count() > $this->limit;
            foreach ($artists->take($this->limit) as $artist) {
                $rows[] = $this->artistRow($artist, 0);
                if ($this->open['artist:'.$artist->id] ?? false) {
                    foreach ($this->albumsOf($artist->id, $hidden) as $album) {
                        $rows[] = $this->albumRow($album, 1, $artist->name);
                        if ($this->open['album:'.$album->id] ?? false) {
                            array_push($rows, ...$this->trackRows(Track::query()->where('album_id', $album->id), $hidden, 2));
                        }
                    }
                }
            }
        } elseif ($this->tab === 'album') {
            $albums = LibrarySources::albums(Album::query()->with('artist')->withCount(['tracks' => fn ($t) => $this->ownTracks($t)])->whereHas('tracks', fn ($t) => $this->ownTracks($t)), $hidden)
                ->when($like, fn ($q) => $q->whereRaw('LOWER(albums.name) LIKE ?', [$like]))
                ->orderByRaw('LOWER(albums.name)')->orderBy('albums.id')->limit($this->limit + 1)->get();
            $more = $albums->count() > $this->limit;
            foreach ($albums->take($this->limit) as $album) {
                $rows[] = $this->albumRow($album, 0, $album->artist?->name);
                if ($this->open['album:'.$album->id] ?? false) {
                    array_push($rows, ...$this->trackRows(Track::query()->where('album_id', $album->id), $hidden, 1));
                }
            }
        } elseif ($this->tab === 'track') {
            $query = $this->ownTracks(LibrarySources::tracks(Track::query()->with(['artist', 'album']), $hidden))
                ->when($like, fn ($q) => $q->whereRaw('LOWER(tracks.name) LIKE ?', [$like]))
                ->orderByRaw('LOWER(tracks.name)')->orderBy('tracks.id')->limit($this->limit + 1)->get();
            $more = $query->count() > $this->limit;
            foreach ($query->take($this->limit) as $track) {
                $rows[] = $this->trackRow($track, 0);
            }
        } else {
            $playlists = LibrarySources::playlists(Playlist::query()->withCount(['tracks' => fn ($t) => $this->ownTracks($t)])->whereHas('tracks', fn ($t) => $this->ownTracks($t)), $hidden)
                ->when($like, fn ($q) => $q->whereRaw('LOWER(playlists.name) LIKE ?', [$like]))
                ->orderByRaw('LOWER(playlists.name)')->orderBy('playlists.id')->limit($this->limit + 1)->get();
            $more = $playlists->count() > $this->limit;
            foreach ($playlists->take($this->limit) as $playlist) {
                $rows[] = $this->base('playlist', $playlist->id, 0) + [
                    'name' => $playlist->name, 'sub' => $playlist->tracks_count.' '.($playlist->tracks_count === 1 ? 'track' : 'tracks'),
                    'art' => PlaylistArtwork::itemFor($playlist)['proxy_120'] ?? null, 'expandable' => true,
                ];
                if ($this->open['playlist:'.$playlist->id] ?? false) {
                    array_push($rows, ...$this->trackRows($playlist->tracks()->getQuery(), $hidden, 1));
                }
            }
        }

        foreach ($rows as &$row) {
            $row['tint'] = LibraryPicks::tint($row['art']);
        }

        return ['rows' => $rows, 'more' => $more];
    }

    /** Albums that have a track with an own file, when only own files are shown. */
    private function withFiles($albums)
    {
        return $this->ownFilesOnly ? $albums->whereHas('tracks', fn ($t) => LibraryPicks::ownFiles($t)) : $albums;
    }

    private function ownTracks($tracks)
    {
        return $this->ownFilesOnly ? LibraryPicks::ownFiles($tracks) : $tracks;
    }

    private function albumsOf(int $artistId, array $hidden)
    {
        return LibrarySources::albums(Album::query()->where('artist_id', $artistId)->withCount(['tracks' => fn ($t) => $this->ownTracks($t)])->whereHas('tracks', fn ($t) => $this->ownTracks($t)), $hidden)
            ->orderByRaw('albums.released_at IS NULL')->orderByDesc('albums.released_at')->orderBy('albums.name')->get();
    }

    /** @return list<array<string, mixed>> */
    private function trackRows($query, array $hidden, int $depth): array
    {
        return $this->ownTracks(LibrarySources::tracks($query->with(['artist', 'album']), $hidden))->limit(200)->get()
            ->map(fn (Track $t) => $this->trackRow($t, $depth))->all();
    }

    private function base(string $type, int $id, int $depth): array
    {
        $key = $type.':'.$id;

        return ['type' => $type, 'id' => $id, 'key' => $key, 'depth' => $depth, 'open' => $this->open[$key] ?? false, 'expandable' => false, 'tint' => ''];
    }

    private function artistRow(Artist $artist, int $depth): array
    {
        return $this->base('artist', $artist->id, $depth) + [
            'name' => $artist->name, 'sub' => $artist->albums_count.' '.($artist->albums_count === 1 ? 'album' : 'albums'),
            'art' => $artist->avatarUrl(), 'expandable' => true,
        ];
    }

    private function albumRow(Album $album, int $depth, ?string $artist): array
    {
        $year = $album->released_at?->year;

        return $this->base('album', $album->id, $depth) + [
            'name' => $album->name, 'sub' => $depth === 0 ? (string) $artist : trim($year.' · '.$album->tracks_count.' '.($album->tracks_count === 1 ? 'track' : 'tracks'), ' ·'),
            'art' => LibraryArtwork::coverUrl($album->images), 'expandable' => true,
        ];
    }

    private function trackRow(Track $track, int $depth): array
    {
        return $this->base('track', $track->id, $depth) + [
            'name' => $track->name,
            'sub' => $depth === 0 ? trim(($track->artist?->name ?? '').($track->album ? ' · '.$track->album->name : ''), ' ·') : (string) $track->artist?->name,
            'art' => LibraryArtwork::coverUrl($track->album?->images),
        ];
    }

    /** @return array<string, mixed>|null */
    private function detail(array $focus, array $hidden): ?array
    {
        $row = LibraryPicks::describe([$focus], $hidden, $this->ownFilesOnly)[0] ?? null;
        if ($row === null) {
            return null;
        }

        return $row + [
            'megabytes' => (int) round($row['tracks'] * LibraryPicks::AVERAGE_MB),
            'picked' => collect($this->picks)->contains(fn ($p) => $p['type'] === $focus['type'] && $p['id'] === $focus['id']),
        ];
    }
}
