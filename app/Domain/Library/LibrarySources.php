<?php

namespace App\Domain\Library;

use App\Domain\Storage\FileIndexer;
use App\Models\Client;
use App\Models\Device;
use App\Models\File;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The sources a client can hide from what it browses: the key is what
 * `clients.hidden_sources` stores, and what `tracks.source` / the `source`
 * of a track's `dlna_url` and `external_id` metadata start with (a DLNA
 * stream's source is `dlna:{server_id}`). Add a future source here and it
 * shows up in the client settings; the filters need no other change.
 *
 * A track is hidden only when every source it is available from is hidden,
 * so a track merged from DLNA and Spotify stays while either is shown.
 * Albums, artists and playlists are hidden when none of their tracks is left.
 */
class LibrarySources
{
    /**
     * The music libraries: id => name (what clients list), kind (`local` or
     * `streaming`, which the legacy scopes of that name select), the label of the hide switch and
     * `device`: the static `fn (Device): bool` callable saying whether a device can play the library
     * (the same check the play endpoints use). Adding a library (Deezer, Tidal) starts here: it then
     * appears in `GET /api/library/libraries` (with its `playable`), is accepted as a `scope`, and
     * can be hidden per client.
     */
    public const LIBRARIES = [
        'spotify' => ['name' => 'Spotify', 'kind' => 'streaming', 'label' => 'Spotify', 'device' => [LibraryPlayback::class, 'canPlaySpotify']],
        'dlna' => ['name' => 'Local', 'kind' => 'local', 'label' => 'DLNA library', 'device' => [LibraryPlayback::class, 'canPlayDlna']],
    ];

    /** Whether $device can play the library $id; false for an unknown library. */
    public static function playableOn(string $id, Device $device): bool
    {
        $check = self::LIBRARIES[$id]['device'] ?? null;

        return $check !== null && (bool) $check($device);
    }

    /** Hideable source that is not a library. */
    public const RADIO = 'radio';

    /** Hideable source key => label: every library plus radio. */
    public static function all(): array
    {
        return [
            ...array_map(fn (array $library) => $library['label'], self::LIBRARIES),
            self::RADIO => 'Radio',
        ];
    }

    /** The libraries as a list shows them: local ones first, then streaming, each in registry order. */
    public static function forDisplay(): array
    {
        $libraries = self::LIBRARIES;
        uksort($libraries, fn ($a, $b) => [$libraries[$a]['kind'] !== 'local'] <=> [$libraries[$b]['kind'] !== 'local']);

        return $libraries;
    }

    /** @return list<string> */
    public static function libraryIds(): array
    {
        return array_keys(self::LIBRARIES);
    }

    /** The libraries of a kind (`local` | `streaming`). @return list<string> */
    public static function libraryIdsOfKind(string $kind): array
    {
        return array_keys(array_filter(self::LIBRARIES, fn (array $library) => $library['kind'] === $kind));
    }

    /** Every library but $id: what to hide to show only $id. @return list<string> */
    public static function allBut(string $id): array
    {
        return array_values(array_diff(self::libraryIds(), [$id]));
    }

    /**
     * The library to show an item under when it is available from several: the leading library if it
     * is among $ids, else the first of $ids in LIBRARIES' order; null for none.
     *
     * @param  list<string>  $ids
     */
    public static function leadingOf(array $ids): ?string
    {
        $leading = LeadingSource::leading();
        if (in_array($leading, $ids, true)) {
            return $leading;
        }
        foreach (self::libraryIds() as $id) {
            if (in_array($id, $ids, true)) {
                return $id;
            }
        }

        return null;
    }

    /** The library id of a track `source` (`dlna:3` is `dlna`); null for anything that isn't a library. */
    public static function libraryOfSource(?string $source): ?string
    {
        $key = is_string($source) ? explode(':', $source, 2)[0] : '';

        return isset(self::LIBRARIES[$key]) ? $key : null;
    }

    /** Metadata keys that make a track available from the metadata row's source. */
    private const SOURCE_METADATA = ['dlna_url', 'external_id'];

    /** Hidden sources that are valid, e.g. what a client stored. */
    public static function normalize(?array $hidden): array
    {
        return array_values(array_intersect(array_keys(self::all()), $hidden ?? []));
    }

    public static function hiddenFor(?Client $client): array
    {
        return self::normalize($client?->hidden_sources);
    }

    /**
     * What to hide for a request: the client's own hidden sources plus those the scope (default the
     * leading source) leaves out. Radio is not part of the library, so it has no effect on these.
     */
    public static function hiddenForRequest(?Client $client, ?string $scope = null): array
    {
        return array_values(array_unique([
            ...self::hiddenFor($client),
            ...LeadingSource::hidden(LeadingSource::scope($scope)),
        ]));
    }

    /** Whether nothing but radio is hidden, i.e. no library filtering is needed. */
    private static function onlyRadioHidden(array $hidden): bool
    {
        return array_diff($hidden, [self::RADIO]) === [] && !File::whereNotNull('hidden_at')->exists();
    }

    /**
     * The source keys (`dlna`, `spotify`, in ALL's order) the given tracks are available from: each
     * track's own `source` plus the source of its `dlna_url` / `external_id` metadata (load that
     * relation first). A `dlna:{server}` source is reported as `dlna`.
     *
     * @param  iterable<Track>  $tracks
     * @return list<string>
     */
    public static function ofTracks(iterable $tracks): array
    {
        $found = [];
        foreach ($tracks as $track) {
            $sources = [$track->source];
            foreach ($track->metadata as $row) {
                if (in_array($row->key, self::SOURCE_METADATA, true)) {
                    $sources[] = $row->source;
                }
            }
            foreach ($sources as $source) {
                $key = is_string($source) ? explode(':', $source, 2)[0] : '';
                if (isset(self::all()[$key])) {
                    $found[$key] = true;
                }
            }
        }

        return array_values(array_filter(array_keys(self::all()), fn ($key) => isset($found[$key])));
    }

    /** Tracks available from at least one source that isn't hidden. */
    public static function tracks(Builder|Relation $query, array $hidden): Builder|Relation
    {
        $hidden = array_values(array_diff($hidden, [self::RADIO]));
        $query = self::withoutHiddenFiles($query);
        if ($hidden === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($hidden) {
            $q->whereNull('tracks.source')
                ->orWhereNotIn('tracks.source', $hidden)
                ->orWhereExists(function ($m) use ($hidden) {
                    $m->selectRaw('1')->from('metadata')
                        ->whereColumn('metadata.metadatable_id', 'tracks.id')
                        ->where('metadata.metadatable_type', (new Track)->getMorphClass())
                        ->whereIn('metadata.key', self::SOURCE_METADATA)
                        ->where(function ($s) use ($hidden) {
                            foreach ($hidden as $source) {
                                $s->where('metadata.source', '!=', $source)
                                    ->where('metadata.source', 'not like', $source.':%');
                            }
                        });
                });
        });
    }

    /**
     * Leaves out tracks whose own files are all hidden (`files.hidden_at`, set in the storage browser).
     * A track that another source (a DLNA server, Spotify) also plays stays, through that source.
     */
    private static function withoutHiddenFiles(Builder|Relation $query): Builder|Relation
    {
        if (!File::whereNotNull('hidden_at')->exists()) {
            return $query;
        }

        return $query->whereNot(function (Builder $q) {
            $q->whereExists(fn ($f) => $f->selectRaw('1')->from('track_files')->join('files', 'files.id', '=', 'track_files.file_id')
                ->whereColumn('track_files.track_id', 'tracks.id'))
                ->whereNotExists(fn ($f) => $f->selectRaw('1')->from('track_files')->join('files', 'files.id', '=', 'track_files.file_id')
                    ->whereColumn('track_files.track_id', 'tracks.id')->whereNull('files.hidden_at'))
                ->whereNotExists(fn ($m) => $m->selectRaw('1')->from('metadata')
                    ->whereColumn('metadata.metadatable_id', 'tracks.id')
                    ->where('metadata.metadatable_type', (new Track)->getMorphClass())
                    ->whereIn('metadata.key', self::SOURCE_METADATA)
                    ->where('metadata.source', '!=', FileIndexer::URL_SOURCE));
        });
    }

    /** Tracks available from $source (its own `source`, or a DLNA url / external id kept from a merge). */
    public static function availableFrom(Builder|Relation $query, string $source): Builder|Relation
    {
        return $query->where(function (Builder $q) use ($source) {
            $q->where('tracks.source', $source)
                ->orWhereExists(function ($m) use ($source) {
                    $m->selectRaw('1')->from('metadata')
                        ->whereColumn('metadata.metadatable_id', 'tracks.id')
                        ->where('metadata.metadatable_type', (new Track)->getMorphClass())
                        ->whereIn('metadata.key', self::SOURCE_METADATA)
                        ->where(fn ($s) => $s->where('metadata.source', $source)->orWhere('metadata.source', 'like', $source.':%'));
                });
        });
    }

    /** Albums with at least one visible track. */
    public static function albums(Builder|Relation $query, array $hidden): Builder|Relation
    {
        return self::hasVisibleTracks($query, 'tracks', $hidden);
    }

    /** Artists with at least one visible album (the library lists artists by their albums). */
    public static function artists(Builder|Relation $query, array $hidden): Builder|Relation
    {
        if (self::onlyRadioHidden($hidden)) {
            return $query;
        }

        return $query->whereHas('albums', fn (Builder $q) => self::albums($q, $hidden));
    }

    /** Playlists with at least one visible track. */
    public static function playlists(Builder|Relation $query, array $hidden): Builder|Relation
    {
        return self::hasVisibleTracks($query, 'tracks', $hidden);
    }

    private static function hasVisibleTracks(Builder|Relation $query, string $relation, array $hidden): Builder|Relation
    {
        if (self::onlyRadioHidden($hidden)) {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $q) => self::tracks($q, $hidden));
    }
}
