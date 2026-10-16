<?php

namespace App\Domain\Library;

use App\Domain\Helpers\TimeHelper;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything /albums/{album} shows, assembled once so the controller and view stay thin: the tracks in album
 * order for the selected source (streaming is never merged with local in one list), the sources the album
 * has, the meta line, genres and tags, sound, credits and play stats, and which rows the pinned player can play.
 */
final class AlbumPage
{
    /**
     * @param  list<string>  $sources  library ids to switch between: where the album has tracks, plus Spotify while connected
     * @param  bool  $spotifyAlbum  the Spotify side is selected: the page shows the album as Spotify has it (AlbumMoreOnSpotify)
     * @param  Collection<int, Track>  $tracks  of the selected source, in album order
     * @param  Collection<int, Track>  $allTracks  of every source, in album order
     * @param  array<int, bool>  $playable  track id => whether the pinned player can play it (empty without a pinned player)
     * @param  list<string>  $tags
     * @param  list<string>  $genres
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public readonly Album $album,
        public readonly array $sources,
        public readonly ?string $source,
        public readonly Collection $tracks,
        public readonly Collection $allTracks,
        public readonly array $playable,
        public readonly int $totalSeconds,
        public readonly string $metaLine,
        public readonly array $genres,
        public readonly array $tags,
        public readonly array $details,
        public readonly ?array $sound,
        public readonly array $credits,
        public readonly int $totalPlays,
        public readonly int $listenedSeconds,
        public readonly Collection $recentPlays,
        public readonly bool $spotifyAlbum,
        public readonly bool $removable,
    ) {}

    /**
     * @param  Album  $album  with `tracks` loaded (metadata, analysis, plays_count) and `artist`
     * @param  string|null  $requested  the `?source=` of the request, used when the album has it
     * @param  string  $scope  the scope browsed in (LeadingSource), picks the default source
     */
    public static function build(Album $album, ?string $requested, string $scope, ?Device $pinned = null, bool $spotifyAvailable = false): self
    {
        $all = self::inAlbumOrder($album->tracks);
        $album->setRelation('tracks', $all);

        $sources = array_values(array_intersect(LibrarySources::libraryIds(), LibrarySources::ofTracks($all)));
        // With Spotify connected its side of the album is always one click away, whatever the library holds of it.
        if ($spotifyAvailable && !in_array('spotify', $sources, true)) {
            $sources[] = 'spotify';
        }
        $source = self::selectSource($sources, $requested, $scope);

        $tracks = $source === null ? $all : $all->filter(fn (Track $t) => in_array($source, $t->sources(), true))->values();
        $spotifyAlbum = $source === 'spotify' && $spotifyAvailable;
        $seconds = (int) $tracks->sum('duration');
        $details = $album->details();
        $genres = $album->genres() ?: $album->artist->genres();

        return new self(
            album: $album,
            sources: $sources,
            source: $source,
            tracks: $tracks,
            allTracks: $all,
            playable: self::playableOn($pinned, $tracks, $source),
            totalSeconds: $seconds,
            metaLine: self::metaLine($album, $details, $genres, $tracks->count(), $seconds),
            genres: $genres,
            tags: self::tags($details, $genres),
            details: $details,
            sound: AlbumSound::forAlbums([$album->id])[$album->id] ?? null,
            credits: (array) $details['credits'],
            totalPlays: $album->plays()->count(),
            listenedSeconds: (int) Play::secondsListened(Play::whereHas('track', fn ($q) => $q->where('album_id', $album->id))),
            recentPlays: Play::whereHas('track', fn ($q) => $q->where('album_id', $album->id))
                ->with(['track', 'device', 'radioStation'])->orderByDesc('played_at')->limit(20)->get(),
            spotifyAlbum: $spotifyAlbum,
            removable: $all->isEmpty() || $all->every(fn (Track $t) => $t->isRemovable()),
        );
    }

    /** The requested source if the album has it, else the one the scope browses, else the leading one of what it has. */
    public static function selectSource(array $sources, ?string $requested, string $scope): ?string
    {
        if ($requested !== null && in_array($requested, $sources, true)) {
            return $requested;
        }

        $preferred = match (true) {
            $scope === 'local' => 'files',
            $scope === 'streaming' => collect(LibrarySources::libraryIdsOfKind('streaming'))->first(fn ($id) => in_array($id, $sources, true)),
            in_array($scope, LibrarySources::libraryIds(), true) => $scope,
            default => null,
        };

        if ($preferred !== null && in_array($preferred, $sources, true)) {
            return $preferred;
        }

        // Several sources and no scope to go by: the leading one, else local before streaming.
        return in_array(LeadingSource::leading(), $sources, true)
            ? LeadingSource::leading()
            : (array_values(array_intersect(array_keys(LibrarySources::forDisplay()), $sources))[0] ?? null);
    }

    /** Album order: disc, then track number; tracks without a position last, by plays. */
    private static function inAlbumOrder(Collection $tracks): Collection
    {
        return $tracks->sortBy(fn (Track $t) => [
            (int) ($t->metaValue('disc_number') ?: 1),
            (int) ($t->metaValue('track_number') ?: 9999),
            -$t->plays_count,
        ])->values();
    }

    /**
     * Whether the pinned player can play each track from the selected source, judged once per album: the two library paths are
     * checked once for the device, not once per track.
     *
     * @return array<int, bool>
     */
    private static function playableOn(?Device $device, Collection $tracks, ?string $source): array
    {
        if ($device === null) {
            return [];
        }

        // Only the selected source counts: a Spotify-only device gets no play buttons on the Local list, and the other way round.
        $dlna = $source !== 'spotify' && LibraryPlayback::canPlayDlna($device);
        $spotify = !in_array($source, ['files', 'dlna'], true) && LibraryPlayback::canPlaySpotify($device);

        return $tracks->mapWithKeys(fn (Track $t) => [
            $t->id => ($dlna && $t->getDlnaUrl()) || ($spotify && LibraryPlayback::spotifyUri($t) !== null),
        ])->all();
    }

    /** `2024 · Nu Metal · 11 tracks · 32 min`, then what is known of the release. */
    private static function metaLine(Album $album, array $details, array $genres, int $count, int $seconds): string
    {
        return collect([
            $album->released_at?->year,
            $genres[0] ?? null,
            $count > 0 ? $count.' '.Str::plural('track', $count) : null,
            $seconds > 0 ? TimeHelper::humanDuration($seconds) : null,
            $details['release_type'] ? ucfirst($details['release_type']) : null,
            $details['format'],
            $details['label'],
            $details['rating'] ? '★ '.$details['rating'].' / 10' : null,
        ])->filter()->implode(' · ');
    }

    /** @return list<string> */
    private static function tags(array $details, array $genres): array
    {
        return collect([$details['mood'], $details['theme'], $details['style'], ...$details['tags']])->filter()
            ->reject(fn ($tag) => collect($genres)->contains(fn ($g) => strcasecmp($g, $tag) === 0))
            ->unique()->take(8)->values()->all();
    }
}
