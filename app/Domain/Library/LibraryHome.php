<?php

namespace App\Domain\Library;

use App\Domain\Artwork\ArtworkTint;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\RadioStation;
use Illuminate\Support\Collection;

/**
 * Everything the library page (`/library`) shows for a scope: the hero, the shelves, favourites and the
 * enrichment blocks. One place, so the controller stays thin and the shelves cost a bounded number of queries
 * (every artwork cover is read from the cache in one round trip, see LibraryItemArtwork::preload).
 */
final class LibraryHome
{
    /** Items per shelf. */
    public const SHELF = 18;

    /** Covers in the hero collage. */
    public const COLLAGE = 8;

    /** The hero's title: the scope toggle says "All", a page title reads better as "Everything". */
    private const NAMES = ['local' => 'Local', 'streaming' => 'Streaming', 'all' => 'Everything'];

    /** Icon (Font Awesome solid) of each scope's hero tile. */
    private const ICONS = ['local' => 'hard-drive', 'streaming' => 'cloud', 'all' => 'layer-group'];

    /**
     * @param  list<string>  $hidden  sources the scope hides (LeadingSource::hidden())
     * @return array<string, mixed>
     */
    public static function build(string $scope, array $hidden): array
    {
        $cardMeta = ['artist', 'genreRelation', 'metadata' => fn ($q) => $q->whereIn('key', Album::CARD_METADATA)];

        $recentAdded = LibrarySources::albums(Album::query(), $hidden)
            ->whereHas('tracks')
            ->with($cardMeta)
            ->withSourceFlags()
            ->latest()
            ->limit(self::SHELF)
            ->get();

        ['items' => $recentPlayed, 'artwork' => $playedArtwork] = RecentItems::get($hidden, 12);

        $topArtists = self::artists($hidden)->whereHas('plays')->orderByDesc('plays_count')->limit(12)->get();

        $playlists = LibrarySources::playlists(Playlist::query()->whereHas('tracks'), $hidden)
            ->withCount(['tracks' => fn ($q) => LibrarySources::tracks($q, $hidden)])
            ->orderByRaw('last_played_at IS NULL')->orderByDesc('last_played_at')->orderBy('name')
            ->limit(12)
            ->get();
        $playlistArtwork = PlaylistArtwork::choose($playlists);

        $favoriteAlbums = LibrarySources::albums(Album::query()->whereNotNull('favorited_at'), $hidden)
            ->with('artist')->orderByDesc('favorited_at')->orderByDesc('id')->limit(12)->get();
        $favoriteArtists = self::artists($hidden)->whereNotNull('favorited_at')->orderByDesc('favorited_at')->limit(12)->get();

        $genres = LibraryOverview::topGenres($hidden);
        $genreAlbums = LibraryOverview::genreAlbumCounts($hidden, $genres);
        $glance = LibraryOverview::glance($hidden);
        $similarArtists = LibraryOverview::similarArtists($hidden, $topArtists);
        $sound = LibraryOverview::soundBuckets($hidden);
        $topRated = LibraryOverview::topRated($hidden)->with($cardMeta)->withSourceFlags()->limit(6)->get();
        AlbumSound::attach($recentAdded);
        AlbumSound::attach($topRated);

        $allAlbums = $recentAdded->concat($topRated)->concat($favoriteAlbums)->concat($recentPlayed->pluck('album')->filter());
        $allArtists = $topArtists->concat($similarArtists)->concat($favoriteArtists);
        LibraryItemArtwork::preload([
            ...LibraryItemArtwork::urlsOfAlbums($allAlbums),
            ...LibraryItemArtwork::urlsOfAlbums($allArtists->map->coverAlbum()),
            ...array_map(fn ($choice) => $choice['url'] ?? null, [...$playedArtwork, ...$playlistArtwork]),
        ]);

        $hero = self::hero($scope, $glance, $recentAdded, $hidden);

        return [
            'hero' => $hero,
            'counts' => [
                'albums' => $glance['albums'],
                'artists' => $glance['artists'],
                'playlists' => $hero['playlists'],
                'genres' => $genres->count(),
                'radio' => RadioStation::query()->count(),
                'favorites' => self::favoriteCount($hidden),
            ],
            'listeningModes' => $scope === 'local' ? ListeningModes::available() : [],
            'recentPlayed' => $recentPlayed,
            'playedArtwork' => $playedArtwork,
            'recentAdded' => $recentAdded,
            'topArtists' => $topArtists,
            'playlists' => $playlists,
            'playlistArtwork' => $playlistArtwork,
            'genres' => $genres,
            'genreAlbums' => $genreAlbums,
            'favoriteAlbums' => $favoriteAlbums,
            'favoriteArtists' => $favoriteArtists,
            'similarArtists' => $similarArtists,
            'sound' => $sound,
            'topRated' => $topRated,
            'glance' => $glance,
        ];
    }

    /** Artists of the scope with what an artist card reads: album and play counts, albums for the cover. */
    private static function artists(array $hidden)
    {
        return LibrarySources::artists(Artist::query(), $hidden)
            ->withCount(['albums' => fn ($q) => LibrarySources::albums($q, $hidden), 'plays'])
            ->with(['metadata' => fn ($q) => $q->whereIn('key', Artist::PHOTO_METADATA), 'albums' => fn ($q) => LibrarySources::albums($q, $hidden)->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')]);
    }

    private static function favoriteCount(array $hidden): int
    {
        return LibrarySources::albums(Album::query()->whereNotNull('favorited_at'), $hidden)->count()
            + LibrarySources::artists(Artist::query()->whereNotNull('favorited_at'), $hidden)->count();
    }

    /**
     * The hero: the scope's name and counts, the collage of the newest covers and the tint of the first.
     *
     * @return array{name: string, icon: string, albums: int, artists: int, playlists: int, covers: list<string>, tint: string}
     */
    private static function hero(string $scope, array $glance, Collection $recentAdded, array $hidden): array
    {
        return [
            'name' => self::NAMES[$scope] ?? ucfirst($scope),
            'icon' => self::ICONS[$scope] ?? 'layer-group',
            'albums' => $glance['albums'],
            'artists' => $glance['artists'],
            'playlists' => LibrarySources::playlists(Playlist::query()->whereHas('tracks'), $hidden)->count(),
            'covers' => $recentAdded->map(fn (Album $album) => LibraryItemArtwork::proxy(LibraryArtwork::coverUrl($album->images)))
                ->filter()->take(self::COLLAGE)->values()->all(),
            'tint' => ArtworkTint::style($recentAdded->first(fn (Album $album) => !empty($album->colors))?->colors),
        ];
    }
}
