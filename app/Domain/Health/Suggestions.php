<?php

namespace App\Domain\Health;

use App\Domain\Library\Enrichment;
use App\Domain\Library\Normalizer;
use App\Domain\Storage\LibraryFiles;
use App\Jobs\ReplaceCoverFromArchive;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use Illuminate\Support\Facades\DB;

/**
 * Tag and cover suggestions (roadmap 12.4): a review list of what MusicBrainz says about the files and albums that
 * disagree with it. Built only from data the server already holds, so listing needs no network:
 *
 * - `title`, `artist`, `year`: a file whose AcoustID fingerprint named a recording, where the MusicBrainz job used
 *   that same recording (`mb_title`, `mb_artist`, `mb_year`, written by EnrichTrackMusicBrainz). A year is only
 *   suggested when the album has none (the first-release year is not the year of a reissue).
 * - `cover`: an album the `covers` report flags (none, tiny, not square) that has a MusicBrainz release; accepting
 *   fetches the front image from the Cover Art Archive.
 * - `track_numbers`: an album whose own files lack a track number, or share one; reported, nothing to apply.
 *
 * Nothing is applied by itself. Accepting a tag suggestion writes it into the file through the tag editor
 * (`LibraryFiles::retag`: the same writer as `PUT /api/admin/files/{id}/tags`), which rewrites the user's file; the
 * routes are admin only. Dismissing keeps the suggested value as `manual` metadata (`suggestion_dismissed_{field}`),
 * so the same suggestion stays away and a different one comes back.
 */
final class Suggestions
{
    public const DISMISSED = 'suggestion_dismissed_';

    private const FIELDS = ['title', 'artist', 'year'];

    /**
     * Every open suggestion, in a stable order (tags by track, then covers, then albums).
     *
     * @return list<array{id: string, kind: string, field: string, title: string, reason: string, current: ?string, suggested: ?string, can_apply: bool, source: string, track_id: ?int, album_id: ?int, artist_id: ?int, file_id: ?int}>
     */
    public static function all(): array
    {
        $dismissed = self::dismissed();

        return [
            ...array_values(array_filter(self::tagSuggestions(), fn ($s) => ($dismissed[$s['id']] ?? null) !== $s['suggested'])),
            ...array_values(array_filter(self::coverSuggestions(), fn ($s) => ($dismissed[$s['id']] ?? null) !== $s['suggested'])),
            ...self::albumSuggestions(),
        ];
    }

    public static function count(): int
    {
        return count(self::all());
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        return collect(self::all())->firstWhere('id', $id);
    }

    /**
     * Applies a suggestion: a tag is written into the file, a cover is fetched in the background.
     *
     * @return array{status: string, id: string}
     *
     * @throws \InvalidArgumentException when the suggestion is unknown, has gone, or cannot be applied
     */
    public static function accept(string $id): array
    {
        $s = self::find($id) ?? throw new \InvalidArgumentException('This suggestion is not open (it was applied, dismissed or no longer applies).');

        if (!$s['can_apply']) {
            throw new \InvalidArgumentException('This finding has no value to apply; fix the tags by hand.');
        }

        if ($s['kind'] === 'cover') {
            ReplaceCoverFromArchive::dispatch(Album::findOrFail($s['album_id']));

            return ['status' => 'queued', 'id' => $id];
        }

        $file = File::findOrFail($s['file_id']);
        app(LibraryFiles::class)->retag($file, [$s['field'] => $s['suggested']]);

        return ['status' => 'applied', 'id' => $id];
    }

    /** Hides a suggestion until MusicBrainz offers a different value. */
    public static function dismiss(string $id): void
    {
        $s = self::find($id) ?? throw new \InvalidArgumentException('This suggestion is not open.');

        $model = $s['kind'] === 'cover' ? Album::find($s['album_id']) : Track::find($s['track_id']);
        if ($model === null || $s['suggested'] === null) {
            throw new \InvalidArgumentException('This finding cannot be dismissed.');
        }

        Enrichment::save($model, self::DISMISSED.$s['field'], $s['suggested'], 'string', Metadata::MANUAL);
    }

    /** @return list<array<string, mixed>> */
    private static function tagSuggestions(): array
    {
        $morph = (new Track)->getMorphClass();
        $meta = fn (string $key) => '(select value from metadata where metadata.metadatable_id = tracks.id and metadata.metadatable_type = \''.$morph.'\' and metadata.key = \''.$key.'\' and metadata.source = \'musicbrainz\' limit 1)';

        $rows = DB::table('files')
            ->where('files.status', 'present')->whereNull('files.hidden_at')
            ->join('track_files', fn ($j) => $j->on('track_files.file_id', '=', 'files.id')->where('track_files.is_primary', true))
            ->join('tracks', 'tracks.id', '=', 'track_files.track_id')
            ->join('artists', 'artists.id', '=', 'tracks.artist_id')
            ->join('file_analyses', 'file_analyses.file_id', '=', 'files.id')
            ->leftJoin('albums', 'albums.id', '=', 'tracks.album_id')
            ->whereNotNull('file_analyses.acoustid_recording_mbid')
            ->select('files.id as file_id', 'tracks.id as track_id', 'tracks.name', 'tracks.album_id', 'tracks.artist_id', 'artists.name as artist', 'albums.released_at')
            ->selectRaw($meta('mbid').' as recording')
            ->selectRaw('file_analyses.acoustid_recording_mbid as acoustid')
            ->selectRaw($meta('mb_title').' as mb_title')
            ->selectRaw($meta('mb_artist').' as mb_artist')
            ->selectRaw($meta('mb_year').' as mb_year')
            ->orderBy('tracks.id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            // Only a recording the fingerprint confirmed counts; a name search by the MusicBrainz job is a guess.
            if ($r->recording === null || $r->recording !== $r->acoustid) {
                continue;
            }

            $label = "{$r->artist} · {$r->name}";
            $add = function (string $field, ?string $current, string $suggested, string $reason) use (&$out, $r, $label) {
                $out[] = [
                    'id' => "{$field}:{$r->track_id}", 'kind' => 'tag', 'field' => $field, 'title' => $label, 'reason' => $reason,
                    'current' => $current, 'suggested' => $suggested, 'can_apply' => true, 'source' => 'musicbrainz',
                    'track_id' => (int) $r->track_id, 'album_id' => $r->album_id ? (int) $r->album_id : null,
                    'artist_id' => $r->artist_id ? (int) $r->artist_id : null, 'file_id' => (int) $r->file_id,
                ];
            };

            $mbTitle = self::collapse($r->mb_title);
            if ($mbTitle !== '' && $mbTitle !== self::collapse($r->name)) {
                $add('title', $r->name, $mbTitle, 'MusicBrainz spells this recording differently.');
            }

            $mbArtist = self::collapse($r->mb_artist);
            if ($mbArtist !== '' && Normalizer::artist($mbArtist) !== Normalizer::artist($r->artist)) {
                $add('artist', $r->artist, $mbArtist, 'MusicBrainz credits this recording to another artist.');
            }

            if ($r->mb_year && $r->album_id && $r->released_at === null) {
                $add('year', null, $r->mb_year, 'The album has no year; MusicBrainz first released this recording in '.$r->mb_year.'.');
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function coverSuggestions(): array
    {
        $problems = collect(LibraryReports::coverProblems())
            ->filter(fn ($p) => collect($p['reasons'])->contains(fn ($r) => str_starts_with($r, 'No cover') || str_starts_with($r, 'Cover is')));

        if ($problems->isEmpty()) {
            return [];
        }

        $albums = Album::query()->findMany($problems->pluck('album_id'))->keyBy('id');
        $out = [];

        foreach ($problems as $p) {
            $album = $albums[$p['album_id']] ?? null;
            $release = $album ? Enrichment::mbid($album) : null;

            if ($release === null) {
                continue;
            }

            $out[] = [
                'id' => "cover:{$p['album_id']}", 'kind' => 'cover', 'field' => 'cover', 'title' => "{$p['artist']} · {$p['album']}",
                'reason' => implode('; ', $p['reasons']), 'current' => null, 'suggested' => $release, 'can_apply' => true,
                'source' => 'coverartarchive', 'track_id' => null, 'album_id' => (int) $p['album_id'], 'artist_id' => $p['artist_id'], 'file_id' => null,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function albumSuggestions(): array
    {
        $morph = (new Track)->getMorphClass();

        $rows = DB::table('tracks')
            ->join('albums', 'albums.id', '=', 'tracks.album_id')
            ->join('artists', 'artists.id', '=', 'albums.artist_id')
            ->join('track_files', fn ($j) => $j->on('track_files.track_id', '=', 'tracks.id')->where('track_files.is_primary', true))
            ->join('files', fn ($j) => $j->on('files.id', '=', 'track_files.file_id')->where('files.status', 'present')->whereNull('files.hidden_at'))
            ->leftJoin('metadata', fn ($j) => $j->on('metadata.metadatable_id', '=', 'tracks.id')->where('metadata.metadatable_type', $morph)->where('metadata.key', 'track_number')->where('metadata.source', 'files'))
            ->select('albums.id as album_id', 'albums.name as album', 'albums.artist_id', 'artists.name as artist', 'tracks.id as track_id', 'metadata.value as number')
            ->orderBy('albums.id')->orderBy('tracks.id')
            ->get()->groupBy('album_id');

        $out = [];
        foreach ($rows as $albumId => $tracks) {
            if ($tracks->count() < 2) {
                continue;
            }

            $numbers = $tracks->pluck('number')->map(fn ($n) => (int) $n);
            $missing = $numbers->filter(fn ($n) => $n === 0)->count();
            $dupes = $numbers->filter(fn ($n) => $n > 0)->countBy()->filter(fn ($c) => $c > 1)->keys();
            $reasons = array_filter([
                $missing > 0 && $missing < $tracks->count() ? "{$missing} of {$tracks->count()} tracks have no track number" : null,
                $dupes->isNotEmpty() ? 'track number '.$dupes->join(', ').' is used more than once (a multi-disc album tags a disc number too)' : null,
            ]);

            if ($reasons) {
                $first = $tracks->first();
                $out[] = [
                    'id' => "track_numbers:{$albumId}", 'kind' => 'album', 'field' => 'track_numbers', 'title' => "{$first->artist} · {$first->album}",
                    'reason' => 'Inconsistent tags within the album: '.implode('; ', $reasons).'.', 'current' => null, 'suggested' => null, 'can_apply' => false,
                    'source' => 'files', 'track_id' => null, 'album_id' => (int) $albumId, 'artist_id' => $first->artist_id ? (int) $first->artist_id : null, 'file_id' => null,
                ];
            }
        }

        return $out;
    }

    /** @return array<string, string> suggestion id => the value that was dismissed */
    private static function dismissed(): array
    {
        $out = [];
        $types = ['track' => (new Track)->getMorphClass(), 'cover' => (new Album)->getMorphClass()];

        Metadata::query()->where('source', Metadata::MANUAL)->where('key', 'like', self::DISMISSED.'%')->get()
            ->each(function (Metadata $m) use (&$out, $types) {
                $field = substr($m->key, strlen(self::DISMISSED));
                if ($field === 'cover' && $m->metadatable_type === $types['cover']) {
                    $out["cover:{$m->metadatable_id}"] = (string) $m->value;
                } elseif (in_array($field, self::FIELDS, true) && $m->metadatable_type === $types['track']) {
                    $out["{$field}:{$m->metadatable_id}"] = (string) $m->value;
                }
            });

        return $out;
    }

    private static function collapse(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{2019}", "\u{2018}"], "'", (string) $value)));
    }
}
