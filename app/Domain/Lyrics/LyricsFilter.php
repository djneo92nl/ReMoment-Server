<?php

namespace App\Domain\Lyrics;

use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** The lyrics filters of `GET /api/library/tracks` (docs/api/lyrics.md): `lyrics_language=nl` and `explicit=1|0`. */
final class LyricsFilter
{
    public const KEYS = ['lyrics_language', 'explicit'];

    public static function rules(): array
    {
        return ['lyrics_language' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'], 'explicit' => ['nullable', 'in:0,1']];
    }

    /** Whether the input carries a lyrics filter. */
    public static function present(array $input): bool
    {
        return ($input['lyrics_language'] ?? '') !== '' || ($input['explicit'] ?? '') !== '';
    }

    /** `explicit=1`: flagged by the source or detected in the lyrics; `0`: no flag at all. */
    public static function apply(Builder $tracks, array $input): Builder
    {
        $meta = fn (string $key) => fn (QueryBuilder $s) => $s->selectRaw('1')->from('metadata')
            ->where('metadata.metadatable_type', (new Track)->getMorphClass())->whereColumn('metadata.metadatable_id', 'tracks.id')->where('metadata.key', $key);

        if (($input['lyrics_language'] ?? '') !== '') {
            $tracks->whereExists(fn ($s) => $meta(LyricsAnalysis::LANGUAGE)($s)->where('metadata.value', strtolower($input['lyrics_language'])));
        }
        if (($input['explicit'] ?? '') !== '') {
            $flagged = fn ($s) => $meta('explicit')($s)->where('metadata.value', '1');
            ((string) $input['explicit']) === '1' ? $tracks->whereExists($flagged) : $tracks->whereNotExists($flagged);
        }

        return $tracks;
    }
}
