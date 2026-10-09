<?php

namespace App\Domain\Library\SmartPlaylist;

use App\Domain\Library\LibrarySources;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Genre;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Everything a smart playlist rule can test, keyed by the field name stored in
 * `playlists.rules`. Add a field (a mood, an energy estimate, …) by adding it
 * to `build()`: the editor and the builder need no other change.
 */
final class SmartFields
{
    private const IS = ['is' => 'is', 'is_not' => 'is not'];

    private const NUMBER = ['eq' => 'is', 'gte' => 'at least', 'lte' => 'at most'];

    private const CONTAINS = ['contains' => 'contains', 'not_contains' => 'does not contain'];

    private const WITHIN = ['within' => 'in the last', 'not_within' => 'not in the last'];

    private const YES_NO = ['yes' => 'yes', 'no' => 'no'];

    private const COMPARE = ['eq' => '=', 'gte' => '>=', 'lte' => '<='];

    /** Mime types of lossless files, as a DLNA server reports them. */
    private const LOSSLESS = ['audio/flac', 'audio/x-flac', 'audio/wav', 'audio/x-wav', 'audio/aiff', 'audio/x-aiff'];

    /** @var array<string, SmartField>|null */
    private static ?array $fields = null;

    /** @return array<string, SmartField> */
    public static function all(): array
    {
        return self::$fields ??= self::build();
    }

    public static function find(mixed $key): ?SmartField
    {
        return is_string($key) ? (self::all()[$key] ?? null) : null;
    }

    /** What the editor needs to draw a rule row. */
    public static function definitions(): array
    {
        return array_map(fn (SmartField $f) => [
            'label' => $f->label,
            'type' => $f->type,
            'operators' => $f->operators,
            'options' => $f->options(),
            'unit' => $f->unit,
        ], self::all());
    }

    /** @return array<string, SmartField> */
    private static function build(): array
    {
        $fields = [
            new SmartField('genre', 'Genre', SmartField::SELECT, self::IS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'is_not', fn ($w) => self::genre($w, (int) $v)),
                fn () => Genre::query()->orderBy('name')->pluck('name', 'id')->all()),

            new SmartField('decade', 'Decade', SmartField::SELECT, self::IS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'is_not', fn ($w) => self::released($w, $v.'-01-01', ($v + 10).'-01-01')),
                fn () => collect(range(1950, 2020, 10))->mapWithKeys(fn ($d) => [$d => $d.'s'])->all()),

            new SmartField('year', 'Release year', SmartField::NUMBER, ['eq' => 'is', 'gte' => 'in or after', 'lte' => 'in or before'],
                fn (Builder $q, string $op, $v) => match ($op) {
                    'eq' => self::released($q, $v.'-01-01', ($v + 1).'-01-01'),
                    'gte' => self::released($q, $v.'-01-01', null),
                    default => self::released($q, null, ($v + 1).'-01-01'),
                }),

            new SmartField('artist', 'Artist', SmartField::TEXT, self::CONTAINS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'not_contains', fn ($w) => $w->whereExists(
                    fn ($s) => $s->selectRaw('1')->from('artists')->whereColumn('artists.id', 'tracks.artist_id')->where('artists.name', 'like', "%{$v}%")
                ))),

            new SmartField('title', 'Track title', SmartField::TEXT, self::CONTAINS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'not_contains', fn ($w) => $w->where('tracks.name', 'like', "%{$v}%"))),

            new SmartField('country', 'Country', SmartField::SELECT, self::IS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'is_not', fn ($w) => $w->where(function ($any) use ($v) {
                    foreach ([[Artist::class, 'tracks.artist_id'], [Album::class, 'tracks.album_id']] as [$type, $column]) {
                        $any->orWhereExists(fn ($s) => self::metadata($s, $type, $column, ['country'])->whereRaw('LOWER(metadata.value) = ?', [mb_strtolower($v)]));
                    }
                })),
                fn () => self::metadataValues('country')),

            new SmartField('label', 'Record label', SmartField::TEXT, self::CONTAINS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'not_contains', fn ($w) => $w->whereExists(
                    fn ($s) => self::metadata($s, Album::class, 'tracks.album_id', ['label'])->where('metadata.value', 'like', "%{$v}%")
                ))),

            new SmartField('release_type', 'Release type', SmartField::SELECT, self::IS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'is_not', fn ($w) => $w->whereExists(
                    fn ($s) => self::metadata($s, Album::class, 'tracks.album_id', ['release_type'])->whereRaw('LOWER(metadata.value) = ?', [mb_strtolower($v)])
                )),
                fn () => self::metadataValues('release_type')),

            new SmartField('tag', 'Last.fm tag', SmartField::TEXT, self::CONTAINS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'not_contains', fn ($w) => $w->where(function ($any) use ($v) {
                    foreach ([[Track::class, 'tracks.id'], [Album::class, 'tracks.album_id'], [Artist::class, 'tracks.artist_id']] as [$type, $column]) {
                        $any->orWhereExists(fn ($s) => self::metadata($s, $type, $column, ['tags'])->where('metadata.value', 'like', "%{$v}%"));
                    }
                }))),

            new SmartField('source', 'Available from', SmartField::SELECT, self::IS,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'is_not', fn ($w) => LibrarySources::availableFrom($w, (string) $v)),
                fn () => collect(LibrarySources::ALL)->except('radio')->all()),

            new SmartField('favorite', 'Favorite (album or artist)', SmartField::BOOL, self::YES_NO,
                fn (Builder $q, string $op) => self::negatable($q, $op === 'no', fn ($w) => $w->where(function ($any) {
                    $any->whereExists(fn ($s) => $s->selectRaw('1')->from('albums')->whereColumn('albums.id', 'tracks.album_id')->whereNotNull('albums.favorited_at'))
                        ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('artists')->whereColumn('artists.id', 'tracks.artist_id')->whereNotNull('artists.favorited_at'));
                }))),

            new SmartField('explicit', 'Explicit', SmartField::BOOL, self::YES_NO,
                fn (Builder $q, string $op) => self::negatable($q, $op === 'no', fn ($w) => $w->whereExists(
                    fn ($s) => self::metadata($s, Track::class, 'tracks.id', ['explicit'])->where('metadata.value', '1')
                ))),

            new SmartField('lossless', 'Lossless file', SmartField::BOOL, self::YES_NO,
                fn (Builder $q, string $op) => self::negatable($q, $op === 'no', fn ($w) => $w->whereExists(
                    fn ($s) => self::metadata($s, Track::class, 'tracks.id', ['mime_type'])->whereIn('metadata.value', self::LOSSLESS)
                ))),

            new SmartField('duration', 'Length', SmartField::NUMBER, ['gte' => 'at least', 'lte' => 'at most'],
                fn (Builder $q, string $op, $v) => $q->where('tracks.duration', self::COMPARE[$op], $v * 60), unit: 'minutes'),

            new SmartField('plays', 'Times played', SmartField::NUMBER, self::NUMBER,
                fn (Builder $q, string $op, $v) => $q->whereRaw('(select count(*) from plays where plays.track_id = tracks.id) '.self::COMPARE[$op].' ?', [$v])),

            new SmartField('skips', 'Times skipped', SmartField::NUMBER, self::NUMBER,
                fn (Builder $q, string $op, $v) => $q->whereRaw('(select count(*) from plays where plays.track_id = tracks.id and plays.skipped = 1) '.self::COMPARE[$op].' ?', [$v])),

            new SmartField('last_played', 'Last played', SmartField::NUMBER, self::WITHIN,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'not_within', fn ($w) => $w->whereExists(
                    fn ($s) => $s->selectRaw('1')->from('plays')->whereColumn('plays.track_id', 'tracks.id')->where('plays.played_at', '>=', now()->subDays($v))
                )), unit: 'days'),

            new SmartField('added', 'Added to the library', SmartField::NUMBER, self::WITHIN,
                fn (Builder $q, string $op, $v) => self::negatable($q, $op === 'not_within', fn ($w) => $w->where('tracks.created_at', '>=', now()->subDays($v))), unit: 'days'),
        ];

        return collect($fields)->keyBy->key->all();
    }

    /** Runs $conditions on a nested group of the query, negated when $not. */
    private static function negatable(Builder $query, bool $not, \Closure $conditions): void
    {
        $not ? $query->whereNot($conditions) : $query->where($conditions);
    }

    /** The track has a canonical genre of its own, or its album or artist has. */
    private static function genre(Builder $query, int $genreId): void
    {
        foreach ([[Track::class, 'tracks.id'], [Album::class, 'tracks.album_id'], [Artist::class, 'tracks.artist_id']] as [$type, $column]) {
            $query->orWhereExists(fn ($s) => $s->selectRaw('1')->from('genreables')
                ->where('genreables.genre_id', $genreId)
                ->where('genreables.genreable_type', (new $type)->getMorphClass())
                ->whereColumn('genreables.genreable_id', $column));
        }
    }

    /** The track's album was released from $from up to, not including, $to (either bound may be open; dates may carry a time). */
    private static function released(Builder $query, ?string $from, ?string $to): void
    {
        $query->whereExists(function ($s) use ($from, $to) {
            $s->selectRaw('1')->from('albums')->whereColumn('albums.id', 'tracks.album_id');
            $from && $s->where('albums.released_at', '>=', $from);
            $to && $s->where('albums.released_at', '<', $to);
        });
    }

    /** A metadata row of $type / the id in $column with one of $keys. */
    private static function metadata(QueryBuilder $query, string $type, string $column, array $keys): QueryBuilder
    {
        return $query->selectRaw('1')->from('metadata')
            ->where('metadata.metadatable_type', (new $type)->getMorphClass())
            ->whereColumn('metadata.metadatable_id', $column)
            ->whereIn('metadata.key', $keys);
    }

    /** The distinct values stored for a metadata key, as options (value => value). */
    private static function metadataValues(string $key): array
    {
        $values = Metadata::query()->where('key', $key)->where('value', '!=', '')->distinct()->orderBy('value')->limit(300)->pluck('value');

        return $values->mapWithKeys(fn ($v) => [$v => $v])->all();
    }
}
