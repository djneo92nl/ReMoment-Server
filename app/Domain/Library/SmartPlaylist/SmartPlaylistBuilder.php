<?php

namespace App\Domain\Library\SmartPlaylist;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySources;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a smart playlist's rules into tracks.
 *
 * A definition is `{ match: all|any, rules: [{ field, operator, value }], sort, limit }`; the fields
 * are in SmartFields. The result is written to `playlist_track`, so playing, the API and the artwork
 * treat a smart playlist like any other. It covers what the leading source shows, without radio stubs.
 */
final class SmartPlaylistBuilder
{
    /** Sort key => label. */
    public const SORTS = [
        'random' => 'Random',
        'recently_added' => 'Recently added',
        'most_played' => 'Most played',
        'least_played' => 'Least played',
        'last_played' => 'Recently played',
        'newest' => 'Newest release',
        'oldest' => 'Oldest release',
        'name' => 'Track title',
    ];

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    public const MAX_RULES = 12;

    private const PLAYS = '(select count(*) from plays where plays.track_id = tracks.id)';

    private const RELEASED = '(select albums.released_at from albums where albums.id = tracks.album_id)';

    public static function defaults(): array
    {
        return ['match' => 'all', 'rules' => [], 'sort' => 'random', 'limit' => self::DEFAULT_LIMIT];
    }

    /** A definition with only known fields, operators and usable values: what is stored and queried. */
    public static function normalize(?array $definition): array
    {
        $definition ??= [];
        $rules = [];

        foreach (array_slice(array_values($definition['rules'] ?? []), 0, self::MAX_RULES) as $rule) {
            $field = SmartFields::find($rule['field'] ?? null);
            $operator = $rule['operator'] ?? null;
            if ($field === null || !is_string($operator) || !$field->hasOperator($operator)) {
                continue;
            }
            $value = $field->cleanValue($rule['value'] ?? null);
            if ($value === null) {
                continue;
            }
            $rules[] = ['field' => $field->key, 'operator' => $operator, 'value' => $value];
        }

        $limit = (int) ($definition['limit'] ?? self::DEFAULT_LIMIT);

        return [
            'match' => ($definition['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'rules' => $rules,
            'sort' => isset(self::SORTS[$definition['sort'] ?? '']) ? $definition['sort'] : 'random',
            'limit' => $limit >= 1 ? min($limit, self::MAX_LIMIT) : self::DEFAULT_LIMIT,
        ];
    }

    /** Every track the rules match, unsorted and unlimited. */
    public static function query(array $definition): Builder
    {
        $definition = self::normalize($definition);

        $query = Track::query()->where(fn (Builder $q) => $q->whereNull('tracks.source')->orWhere('tracks.source', '!=', 'radio'));
        LibrarySources::tracks($query, LeadingSource::hidden(LeadingSource::defaultScope()));

        if ($definition['rules'] !== []) {
            $any = $definition['match'] === 'any';
            $query->where(function (Builder $group) use ($definition, $any) {
                foreach ($definition['rules'] as $rule) {
                    $group->{$any ? 'orWhere' : 'where'}(fn (Builder $w) => SmartFields::find($rule['field'])->apply($w, $rule['operator'], $rule['value']));
                }
            });
        }

        return $query;
    }

    /** How many tracks the rules match, before the limit. */
    public static function count(array $definition): int
    {
        return self::query($definition)->count();
    }

    /** The tracks the playlist gets: sorted, limited. */
    public static function tracks(array $definition): Builder
    {
        $definition = self::normalize($definition);

        return self::sort(self::query($definition), $definition['sort'])->limit($definition['limit']);
    }

    /** Rewrites the playlist's tracks from its rules. Idempotent apart from random order. Returns the track count. */
    public static function refresh(Playlist $playlist): int
    {
        $ids = self::tracks($playlist->rules ?? [])->pluck('tracks.id');
        $now = now();

        DB::transaction(function () use ($playlist, $ids, $now) {
            DB::table('playlist_track')->where('playlist_id', $playlist->id)->delete();

            foreach ($ids->values()->chunk(500) as $chunk) {
                DB::table('playlist_track')->insert($chunk->map(fn ($id, $i) => [
                    'playlist_id' => $playlist->id,
                    'track_id' => $id,
                    'position' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            $playlist->forceFill(['refreshed_at' => $now])->save();
        });

        return $ids->count();
    }

    private static function sort(Builder $query, string $sort): Builder
    {
        match ($sort) {
            'recently_added' => $query->orderByDesc('tracks.created_at')->orderByDesc('tracks.id'),
            'most_played' => $query->orderByRaw(self::PLAYS.' desc'),
            'least_played' => $query->orderByRaw(self::PLAYS.' asc'),
            'last_played' => $query->orderByRaw('(select max(plays.played_at) from plays where plays.track_id = tracks.id) desc'),
            'newest' => $query->orderByRaw(self::RELEASED.' desc'),
            'oldest' => $query->orderByRaw('CASE WHEN '.self::RELEASED.' IS NULL THEN 1 ELSE 0 END')->orderByRaw(self::RELEASED.' asc'),
            'name' => $query->orderBy('tracks.name'),
            default => $query->inRandomOrder(),
        };

        return $sort === 'random' ? $query : $query->orderBy('tracks.id');
    }

    /** @return Collection<int, Track> */
    public static function preview(array $definition, int $limit = 10): Collection
    {
        return self::tracks($definition)->with('artist')->limit($limit)->get();
    }
}
