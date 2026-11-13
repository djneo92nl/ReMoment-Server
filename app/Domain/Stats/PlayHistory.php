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

    /** @return array{data: list<array>, next_cursor: string|null} newest first, keyset-paged on (played_at, id) */
    public static function page(Builder $plays, ?string $cursor, int $limit = self::PAGE): array
    {
        if ($cursor !== null && $cursor !== '') {
            [$at, $id] = self::decode($cursor);
            $plays->where(fn ($w) => $w->where('plays.played_at', '<', $at)
                ->orWhere(fn ($e) => $e->where('plays.played_at', $at)->where('plays.id', '<', $id)));
        }

        $rows = $plays
            ->with(['device', 'radioStation', 'track.artist', 'track.album', 'track.metadata' => fn ($q) => $q->whereIn('key', LibrarySources::SOURCE_METADATA)])
            ->orderByDesc('plays.played_at')->orderByDesc('plays.id')
            ->limit($limit + 1)->get();
        $page = $rows->take($limit);

        LibraryItemArtwork::preload([
            ...LibraryItemArtwork::urlsOfAlbums($page->map(fn (Play $p) => $p->track?->album)->filter()),
            ...$page->map(fn (Play $p) => $p->image_url)->filter()->all(),
            ...$page->map(fn (Play $p) => $p->radioStation?->image_url)->filter()->all(),
        ]);

        $last = $page->last();

        return [
            'data' => $page->map(fn (Play $p) => self::entry($p))->values()->all(),
            'next_cursor' => $rows->count() > $limit && $last ? self::encode($last) : null,
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
