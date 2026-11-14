<?php

namespace App\Domain\Stats;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Library\LibrarySources;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** The play log as a list for clients (GET /api/library/history and the per-device, per-album, per-artist variants; docs/api/history.md). */
class PlayHistory
{
    public const PAGE = 50;

    public const MATCH_LIBRARY = 'library';

    public const MATCH_UNKNOWN = 'unknown';

    /** Plays of library tracks outside the scope are left out; plays without a library track always stay. Filters by day (server timezone) and text. */
    public static function filtered(?string $from, ?string $to, ?string $q, ?array $hidden): Builder
    {
        $query = Play::query();

        if ($hidden !== null) {
            $query->where(fn ($w) => $w->whereNull('plays.track_id')
                ->orWhereIn('plays.track_id', LibrarySources::tracks(Track::query()->select('tracks.id'), $hidden)));
        }
        if ($from !== null) {
            $query->where('plays.played_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to !== null) {
            $query->where('plays.played_at', '<', Carbon::parse($to)->startOfDay()->addDay());
        }
        if ($q !== null && trim($q) !== '') {
            $like = '%'.addcslashes(trim($q), '%_\\').'%';
            $query->where(fn ($w) => $w
                ->whereHas('track', fn ($t) => $t->where('name', 'like', $like)
                    ->orWhereHas('artist', fn ($a) => $a->where('name', 'like', $like))
                    ->orWhereHas('album', fn ($a) => $a->where('name', 'like', $like)))
                ->orWhere('plays.track_name', 'like', $like)->orWhere('plays.artist_name', 'like', $like)
                ->orWhere('plays.album_name', 'like', $like)->orWhere('plays.radio_name', 'like', $like)
                ->orWhere('plays.source_name', 'like', $like));
        }

        return $query;
    }

    /** Only the plays on this device. */
    public static function onDevice(Builder $plays, int $deviceId): Builder
    {
        return $plays->where('plays.device_id', $deviceId);
    }

    /** Only the plays logged for this source type (`dlna`, `spotify`, `radio`, `source` …). */
    public static function ofSourceType(Builder $plays, string $sourceType): Builder
    {
        return $plays->where('plays.source_type', $sourceType);
    }

    /** Plays of a track in the own library (`library`) versus plays that match none: logged as text, or a radio stub (`unknown`). */
    public static function ofMatch(Builder $plays, string $match): Builder
    {
        $stubs = Track::query()->select('tracks.id')->where('tracks.source', 'radio');

        return $match === self::MATCH_LIBRARY
            ? $plays->whereNotNull('plays.track_id')->whereNotIn('plays.track_id', $stubs)
            : $plays->where(fn ($w) => $w->whereNull('plays.track_id')->orWhereIn('plays.track_id', $stubs));
    }

    /** Every play of the album's tracks. */
    public static function ofAlbum(Builder $plays, int $albumId): Builder
    {
        return $plays->whereIn('plays.track_id', Track::query()->select('tracks.id')->where('tracks.album_id', $albumId));
    }

    /** Every play of the artist's tracks. */
    public static function ofArtist(Builder $plays, int $artistId): Builder
    {
        return $plays->whereIn('plays.track_id', Track::query()->select('tracks.id')->where('tracks.artist_id', $artistId));
    }

    /**
     * One page of plays as models, newest first, keyset-paged on (played_at, id): the list the API and the /history page share.
     *
     * @return array{plays: \Illuminate\Support\Collection<int, Play>, next_cursor: string|null}
     */
    public static function fetch(Builder $plays, ?string $cursor, int $limit = self::PAGE): array
    {
        if ($cursor !== null && $cursor !== '') {
            [$at, $id] = self::decode($cursor);
            $plays->where(fn ($w) => $w->where('plays.played_at', '<', $at)
                ->orWhere(fn ($e) => $e->where('plays.played_at', $at)->where('plays.id', '<', $id)));
        }

        $rows = $plays
            ->with(['device', 'radioStation', 'track' => fn ($q) => $q->withExists('trackFiles'), 'track.artist', 'track.album', 'track.metadata' => fn ($q) => $q->whereIn('key', LibrarySources::SOURCE_METADATA)])
            ->orderByDesc('plays.played_at')->orderByDesc('plays.id')
            ->limit($limit + 1)->get();
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return [
            'plays' => $page,
            'next_cursor' => $rows->count() > $limit && $last ? self::encode($last) : null,
        ];
    }

    /** @return array{data: list<array>, next_cursor: string|null} the API shape of fetch() */
    public static function page(Builder $plays, ?string $cursor, int $limit = self::PAGE): array
    {
        ['plays' => $page, 'next_cursor' => $next] = self::fetch($plays, $cursor, $limit);

        LibraryItemArtwork::preload([
            ...LibraryItemArtwork::urlsOfAlbums($page->map(fn (Play $p) => $p->track?->album)->filter()),
            ...$page->map(fn (Play $p) => $p->image_url)->filter()->all(),
            ...$page->map(fn (Play $p) => $p->radioStation?->image_url)->filter()->all(),
        ]);

        return [
            'data' => $page->map(fn (Play $p) => self::entry($p))->values()->all(),
            'next_cursor' => $next,
        ];
    }

    public static function entry(Play $play): array
    {
        $track = $play->track;
        $listened = $play->ended_at === null ? null : max(0, (int) $play->played_at->diffInSeconds($play->ended_at));
        $station = $play->radioStation;
        $type = $play->source_type === 'radio' || $station !== null || $play->radio_name !== null
            ? 'radio'
            : ($play->isTrackPlay() ? 'track' : 'source');

        return [
            'id' => $play->id,
            'type' => $type,
            'name' => match ($type) {
                'radio' => $play->radio_name ?? $station?->name,
                'track' => $play->trackTitle(),
                default => $play->source_name,
            },
            'artist_name' => $play->artistTitle(),
            'album_name' => $play->albumTitle(),
            'track_id' => $play->track_id,
            'album_id' => $track?->album_id,
            'artist_id' => $track?->artist_id,
            'station' => $station ? ['id' => $station->id, 'name' => $station->name] : null,
            'device' => $play->device ? ['id' => $play->device->id, 'name' => $play->device->device_name] : null,
            'library' => $track ? (LibrarySources::ofTracks([$track])[0] ?? null) : null,
            'source_type' => $play->source_type,
            'artwork' => $track
                ? (LibraryItemArtwork::forImages($track->album?->images) ?? LibraryItemArtwork::forUrl($play->image_url))
                : LibraryItemArtwork::forUrl($play->image_url ?? $station?->image_url),
            'duration' => $track?->duration ?? $play->duration,
            'started_at' => $play->played_at->toIso8601String(),
            'ended_at' => $play->ended_at?->toIso8601String(),
            'listened_seconds' => $listened,
            'completed' => $play->ended_at !== null && !$play->skipped,
            'skipped' => (bool) $play->skipped,
        ];
    }

    private static function encode(Play $last): string
    {
        return rtrim(strtr(base64_encode('t:'.$last->played_at->format('Y-m-d H:i:s').'|'.$last->id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int} */
    private static function decode(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || !preg_match('/^t:(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,12})$/', $decoded, $m)) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return [$m[1], (int) $m[2]];
    }
}
