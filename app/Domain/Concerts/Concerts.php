<?php

namespace App\Domain\Concerts;

use App\Domain\Library\Enrichment;
use App\Domain\Library\LibrarySources;
use App\Models\Concert;
use App\Models\Media\Artist;
use App\Services\MusicBrainz\MusicBrainzClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Upcoming concerts of the artists in the library (roadmap 12.6, docs/api/concerts.md). The source is MusicBrainz events
 * (no key): `sync()` browses the events of an artist's MusicBrainz id and keeps the ones that have not happened yet in
 * `concerts`; reads filter by the location setting. MusicBrainz events are added by its editors, so coverage is thin for
 * small artists: an empty answer means "none known", not "none".
 */
final class Concerts
{
    /** How many 100-event pages of one artist are read (one request a second); old artists have hundreds of past events. */
    private const MAX_PAGES = 3;

    /** The marker (artist metadata, source `musicbrainz-events`) of the last time an artist was synced. */
    public const CHECKED = 'concerts_checked_at';

    public const SOURCE = 'musicbrainz-events';

    private const CACHE_VERSION = 'concerts:version';

    /** @return array<string, mixed>|null the fields of an upcoming, not cancelled event, or null */
    public static function parseEvent(array $event, ?string $today = null): ?array
    {
        $today ??= now()->toDateString();
        $begin = $event['life-span']['begin'] ?? null;
        if (empty($event['id']) || !is_string($begin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $begin) || !empty($event['cancelled'])) {
            return null;   // MusicBrainz dates can be partial (2027, 2027-05): too vague for a concert
        }
        $end = $event['life-span']['end'] ?? null;
        if (max($begin, is_string($end) ? $end : $begin) < $today) {
            return null;
        }

        $place = collect($event['relations'] ?? [])->first(fn ($r) => ($r['target-type'] ?? null) === 'place' && !empty($r['place']))['place'] ?? null;
        $area = collect($event['relations'] ?? [])->first(fn ($r) => ($r['target-type'] ?? null) === 'area' && !empty($r['area']))['area'] ?? null;
        $time = $event['time'] ?? '';

        return [
            'event_mbid' => $event['id'],
            'name' => mb_substr((string) ($event['name'] ?? ''), 0, 250),
            'type' => ($event['type'] ?? '') !== '' ? $event['type'] : null,
            'starts_on' => $begin,
            'ends_on' => is_string($end) && $end > $begin && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : null,
            'starts_at' => preg_match('/^\d{2}:\d{2}/', (string) $time) ? substr($time, 0, 5) : null,
            'venue' => ($place['name'] ?? '') !== '' ? mb_substr($place['name'], 0, 250) : null,
            'city' => ($place['area']['name'] ?? $area['name'] ?? '') !== '' ? mb_substr($place['area']['name'] ?? $area['name'], 0, 250) : null,
            'address' => ($place['address'] ?? '') !== '' ? mb_substr($place['address'], 0, 250) : null,
            'latitude' => isset($place['coordinates']['latitude']) ? round((float) $place['coordinates']['latitude'], 5) : null,
            'longitude' => isset($place['coordinates']['longitude']) ? round((float) $place['coordinates']['longitude'], 5) : null,
        ];
    }

    /**
     * Reads one artist's events from MusicBrainz and replaces the stored ones; false when the artist has no MusicBrainz id.
     * A rate limit or outage throws (the job is retried), so nothing is replaced by a failed read.
     */
    public static function sync(Artist $artist, ?MusicBrainzClient $client = null): bool
    {
        $mbid = Enrichment::mbid($artist);
        if (!$mbid) {
            return false;
        }

        $client ??= new MusicBrainzClient;
        $found = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $client->get('event', ['artist' => $mbid, 'inc' => 'place-rels+area-rels', 'limit' => 100, 'offset' => $page * 100]);
            foreach ($data['events'] ?? [] as $event) {
                if ($row = self::parseEvent($event)) {
                    $found[$row['event_mbid']] = $row;
                }
            }
            if ($data === null || ($page + 1) * 100 >= (int) ($data['event-count'] ?? 0)) {
                break;
            }
        }

        foreach ($found as $row) {
            Concert::updateOrCreate(['artist_id' => $artist->id, 'event_mbid' => $row['event_mbid']], $row);
        }
        Concert::where('artist_id', $artist->id)->whereNotIn('event_mbid', array_keys($found))->delete();
        Enrichment::save($artist, self::CHECKED, now()->toIso8601String(), 'string', self::SOURCE);
        self::flush();

        return true;
    }

    /** Artists of the library (own files and DLNA, not Spotify-only) with a MusicBrainz id, not synced in the last `$days`. */
    public static function dueArtists(int $limit, int $days = 7): Builder
    {
        $mbid = fn ($q) => $q->where('key', 'mbid');
        $fresh = now()->subDays($days)->toIso8601String();

        return LibrarySources::artists(Artist::query(), array_keys(LibrarySources::STREAMING_SERVICES))
            ->whereHas('metadata', $mbid)
            ->whereDoesntHave('metadata', fn ($q) => $q->where('key', self::CHECKED)->where('source', self::SOURCE)->where('value', '>', $fresh))
            ->orderBy('artists.id')->limit($limit);
    }

    /** Drops the concerts that have happened. */
    public static function prune(): int
    {
        $n = Concert::where(fn ($q) => $q->whereDate('starts_on', '<', now()->toDateString())->where(fn ($e) => $e->whereNull('ends_on')->orWhereDate('ends_on', '<', now()->toDateString())))->delete();
        $n > 0 && self::flush();

        return $n;
    }

    /**
     * The upcoming concerts, soonest first, filtered by `$location` (null: the setting; `''`: everywhere).
     *
     * @return array{enabled: bool, location: ?string, data: list<array<string, mixed>>}
     */
    public static function upcoming(?int $artistId = null, ?string $location = null, int $limit = 50): array
    {
        $location ??= ConcertSettings::location();
        if (!ConcertSettings::enabled()) {
            return ['enabled' => false, 'location' => $location === '' ? null : $location, 'data' => []];
        }

        $key = 'concerts:'.Cache::get(self::CACHE_VERSION, 0).':'.md5(json_encode([$artistId, $location, $limit, now()->toDateString()]));
        $data = Cache::remember($key, 600, function () use ($artistId, $location, $limit) {
            $query = Concert::query()->with('artist')
                ->where(fn ($q) => $q->whereDate('starts_on', '>=', now()->toDateString())->orWhereDate('ends_on', '>=', now()->toDateString()))
                ->orderBy('starts_on')->orderBy('id');
            if ($artistId !== null) {
                $query->where('artist_id', $artistId);
            }
            if ($terms = ConcertSettings::terms($location)) {
                $query->where(function ($any) use ($terms) {
                    foreach ($terms as $term) {
                        $like = '%'.str_replace(['%', '_'], '', $term).'%';
                        foreach (['venue', 'city', 'address'] as $column) {
                            $any->orWhereRaw("LOWER(concerts.{$column}) LIKE ?", [$like]);
                        }
                    }
                });
            }

            return $query->limit($limit)->get()->map(fn (Concert $c) => self::present($c))->all();
        });

        return ['enabled' => true, 'location' => $location === '' ? null : $location, 'data' => $data];
    }

    public static function flush(): void
    {
        Cache::put(self::CACHE_VERSION, (int) Cache::get(self::CACHE_VERSION, 0) + 1);
    }

    /** @return array<string, mixed> */
    public static function present(Concert $c): array
    {
        return [
            'id' => $c->id,
            'artist_id' => $c->artist_id,
            'artist_name' => $c->artist?->name,
            'name' => $c->name,
            'type' => $c->type,
            'starts_on' => $c->starts_on->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'starts_at' => $c->starts_at,
            'venue' => $c->venue,
            'city' => $c->city,
            'address' => $c->address,
            'latitude' => $c->latitude,
            'longitude' => $c->longitude,
            'url' => 'https://musicbrainz.org/event/'.$c->event_mbid,
        ];
    }
}
