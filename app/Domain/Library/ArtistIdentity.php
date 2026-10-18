<?php

namespace App\Domain\Library;

use App\Jobs\FetchSpotifyArtistImages;
use App\Models\Media\Artist;
use App\Services\MusicBrainz\MusicBrainzClient;

/**
 * Which MusicBrainz / Spotify / Last.fm artist this library artist is, and fetching their data again. A wrong
 * match (another artist with the same name) is fixed by a manual id (ArtistOverrides): the data the old match
 * brought is dropped and everything is fetched again from the right one. Search calls are made on the spot and
 * may throw on a network error; the caller shows it.
 */
final class ArtistIdentity
{
    /** Sources whose rows and `enriched_at` markers belong to the matched external artist. */
    private const MATCHED = [Enrichment::MUSICBRAINZ, Enrichment::AUDIODB, 'wikipedia'];

    /** Source name => the enrichment job source it is fetched by. */
    public const SOURCES = [
        Enrichment::MUSICBRAINZ => 'MusicBrainz and Wikipedia',
        Enrichment::AUDIODB => 'TheAudioDB',
        Enrichment::LASTFM => 'Last.fm',
    ];

    /** @return array<string, array{value: ?string, manual: bool}> */
    public static function mappings(Artist $artist): array
    {
        $row = fn (string $key) => [
            'value' => $artist->metaValue($key),
            'manual' => ArtistOverrides::isSet($artist, $key),
        ];

        return [
            'mbid' => ['value' => Enrichment::mbid($artist), 'manual' => ArtistOverrides::isSet($artist, 'mbid')],
            'spotify_id' => $row('spotify_id'),
            'lastfm_name' => ['value' => $artist->metaValue('lastfm_name') ?? $artist->name, 'manual' => ArtistOverrides::isSet($artist, 'lastfm_name')],
            'audiodb_id' => $row('audiodb_id'),
            'wikidata_id' => $row('wikidata_id'),
        ];
    }

    /** @return list<array{id: string, name: string, detail: string}> MusicBrainz artists for a name, best match first */
    public static function searchMusicBrainz(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return [];
        }

        $query = 'artist:"'.str_replace('"', '\\"', $name).'"';

        return collect((new MusicBrainzClient)->get('artist', ['query' => $query, 'limit' => 6])['artists'] ?? [])
            ->sortByDesc('score')
            ->map(fn (array $a) => [
                'id' => $a['id'],
                'name' => $a['name'],
                'detail' => collect([
                    $a['disambiguation'] ?? null,
                    isset($a['type']) ? strtolower($a['type']) : null,
                    $a['country'] ?? ($a['area']['name'] ?? null),
                    isset($a['life-span']['begin']) ? 'since '.substr($a['life-span']['begin'], 0, 4) : null,
                ])->filter()->implode(' · '),
            ])->values()->all();
    }

    /** @return list<array{id: string, name: string, image: ?string}> */
    public static function searchSpotify(string $name): array
    {
        $catalog = app(SpotifyCatalog::class);

        return $catalog->available() && trim($name) !== '' ? $catalog->search(trim($name))['artists'] : [];
    }

    /** The MusicBrainz id in an MBID or a musicbrainz.org artist link; null when it isn't one. */
    public static function parseMbid(string $input): ?string
    {
        return preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $input, $m) ? strtolower($m[0]) : null;
    }

    /** The Spotify artist id in an id, a `spotify:artist:` URI or an open.spotify.com link; null when it isn't one. */
    public static function parseSpotifyId(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('#artist[/:]([0-9A-Za-z]{22})#', $input, $m) || preg_match('/^([0-9A-Za-z]{22})$/', $input, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Makes $mbid the artist's MusicBrainz match: what the old match brought goes, everything is fetched again. */
    public static function setMusicBrainz(Artist $artist, string $mbid): void
    {
        self::dropMatched($artist);
        ArtistOverrides::set($artist, 'mbid', $mbid);
        self::refetch($artist);
    }

    public static function setSpotify(Artist $artist, string $spotifyId): void
    {
        ArtistOverrides::set($artist, 'spotify_id', $spotifyId);
        self::refetch($artist, [], spotify: true);
    }

    public static function setLastfmName(Artist $artist, string $name): void
    {
        ArtistOverrides::set($artist, 'lastfm_name', trim($name));
        self::refetch($artist, [Enrichment::LASTFM]);
    }

    /** Goes back to the automatic match for one mapping and fetches again. */
    public static function reset(Artist $artist, string $key): void
    {
        ArtistOverrides::clear($artist, $key);

        match ($key) {
            'mbid' => (function () use ($artist) {
                self::dropMatched($artist);
                self::refetch($artist);
            })(),
            'spotify_id' => self::refetch($artist, [], spotify: true),
            'lastfm_name' => self::refetch($artist, [Enrichment::LASTFM]),
            default => null,
        };
    }

    /**
     * Fetches the data of some sources again (all external ones when $sources is empty) and, with $spotify, the Spotify photo.
     *
     * @param  list<string>  $sources  keys of self::SOURCES
     * @return int jobs queued
     */
    public static function refetch(Artist $artist, array $sources = [], bool $spotify = false): int
    {
        $sources = $sources === [] && !$spotify ? array_keys(self::SOURCES) : $sources;
        $artist->unsetRelation('metadata');

        // The old single-job marker counts as done for every source: turn it into one marker per source first.
        $legacy = $artist->metadata()->where('key', 'enriched_at')->where('source', 'system')->exists();
        if ($legacy) {
            $artist->metadata()->where('key', 'enriched_at')->where('source', 'system')->delete();
            foreach (array_diff(array_keys(self::SOURCES), $sources) as $other) {
                Enrichment::markDone($artist, $other);
            }
        }

        $markers = collect($sources)->flatMap(fn ($s) => $s === Enrichment::MUSICBRAINZ ? [$s, 'wikipedia'] : [$s])->all();
        $artist->metadata()->where('key', 'enriched_at')->whereIn('source', $markers)->delete();
        $artist->unsetRelation('metadata');

        $queued = Enrichment::queueArtist($artist);

        if ($spotify) {
            FetchSpotifyArtistImages::dispatch([$artist->id]);
            $queued++;
        }

        return $queued;
    }

    /** Renames the artist; false when the name is blank. The comparison key follows (Artist's saving hook). */
    public static function rename(Artist $artist, string $name): bool
    {
        $name = trim($name);

        if ($name === '') {
            return false;
        }

        $artist->update(['name' => $name]);

        return true;
    }

    /** @return list<array{id: int, name: string, albums: int, tracks: int}> other library artists whose name contains $term */
    public static function searchLibrary(Artist $artist, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        return Artist::query()->whereKeyNot($artist->id)
            ->where('name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%')
            ->withCount(['albums', 'tracks'])->orderBy('name')->limit(8)->get()
            ->map(fn (Artist $a) => ['id' => $a->id, 'name' => $a->name, 'albums' => $a->albums_count, 'tracks' => $a->tracks_count])->all();
    }

    /** Folds another artist into this one (albums, tracks, plays, metadata); returns how many artists were merged away. */
    public static function mergeIn(Artist $artist, int $otherId): int
    {
        $merged = app(LibraryMerger::class)->mergeArtists($artist->id, [$otherId]);
        $artist->unsetRelation('metadata');

        return $merged;
    }

    /** Drops what the MusicBrainz match brought (and what hangs on it: TheAudioDB, Wikipedia), so a new match starts clean. */
    private static function dropMatched(Artist $artist): void
    {
        $artist->metadata()->whereIn('source', self::MATCHED)->delete();
        $artist->genreRelation()->wherePivotIn('source', self::MATCHED)->detach();
        $artist->unsetRelation('metadata');
    }
}
