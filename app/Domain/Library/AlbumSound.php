<?php

namespace App\Domain\Library;

use Illuminate\Support\Facades\DB;

/**
 * What the audio analysis (App\Domain\Analysis) says about a whole album, from its tracks' primary files:
 * average tempo, loudness and danceability, the moods and keys that come up most. Only albums with analysed
 * own files have one. One query for any number of albums.
 */
final class AlbumSound
{
    public const MOODS = ['happy', 'sad', 'aggressive', 'relaxed', 'party', 'acoustic', 'electronic'];

    /**
     * Sets `sound` on every album (null when none of its tracks is analysed).
     *
     * @param  iterable<\App\Models\Media\Album>  $albums
     */
    public static function attach(iterable $albums): void
    {
        $albums = collect($albums);
        $sounds = self::forAlbums($albums->pluck('id')->all());

        foreach ($albums as $album) {
            $album->setAttribute('sound', $sounds[$album->id] ?? null);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{tracks: int, bpm: ?int, loudness: ?float, danceability: ?float, moods: list<string>, keys: list<string>}>
     */
    public static function forAlbums(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('tracks')
            ->join('track_files', fn ($join) => $join->on('track_files.track_id', '=', 'tracks.id')->where('track_files.is_primary', true))
            ->join('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
            ->whereIn('tracks.album_id', $ids)
            ->get(['tracks.album_id', 'file_analyses.bpm', 'file_analyses.loudness_lufs', 'file_analyses.danceability',
                'file_analyses.mood_tags', 'file_analyses.key', 'file_analyses.mode'])
            ->groupBy('album_id');

        $sounds = [];

        foreach ($rows as $albumId => $tracks) {
            $avg = fn (string $column) => ($values = $tracks->pluck($column)->filter(fn ($v) => $v !== null)) && $values->isNotEmpty()
                ? $values->avg() : null;

            $moods = $tracks->flatMap(fn ($t) => array_column(json_decode($t->mood_tags ?? '[]', true) ?: [], 'name'))
                ->countBy()->sortDesc()->keys()->take(4)->all();
            $keys = $tracks->filter(fn ($t) => $t->key)->map(fn ($t) => trim($t->key.' '.$t->mode))
                ->countBy()->sortDesc()->keys()->take(3)->all();

            $bpm = $avg('bpm');
            $loudness = $avg('loudness_lufs');
            $dance = $avg('danceability');

            $sounds[(int) $albumId] = [
                'tracks' => $tracks->count(),
                'bpm' => $bpm !== null ? (int) round($bpm) : null,
                'loudness' => $loudness !== null ? round($loudness, 1) : null,
                'danceability' => $dance !== null ? round($dance, 2) : null,
                'moods' => $moods,
                'keys' => $keys,
            ];
        }

        return $sounds;
    }
}
