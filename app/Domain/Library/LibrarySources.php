<?php

namespace App\Domain\Library;

use App\Models\Client;
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
    /** Hideable source key => label. */
    public const ALL = [
        'spotify' => 'Spotify',
        'dlna' => 'DLNA library',
        'radio' => 'Radio',
    ];

    /** Metadata keys that make a track available from the metadata row's source. */
    private const SOURCE_METADATA = ['dlna_url', 'external_id'];

    /** Hidden sources that are valid, e.g. what a client stored. */
    public static function normalize(?array $hidden): array
    {
        return array_values(array_intersect(array_keys(self::ALL), $hidden ?? []));
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
        return array_diff($hidden, ['radio']) === [];
    }

    /** Tracks available from at least one source that isn't hidden. */
    public static function tracks(Builder|Relation $query, array $hidden): Builder|Relation
    {
        $hidden = array_values(array_diff($hidden, ['radio']));
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
