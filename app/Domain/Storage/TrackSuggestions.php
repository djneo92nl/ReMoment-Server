<?php

namespace App\Domain\Storage;

use App\Models\Media\Track;
use App\Services\MusicBrainz\MusicBrainzClient;

/**
 * Candidates for what a file could be, from a hint of title and artist: tracks already in the library and
 * recordings MusicBrainz knows. The tag editor lets the user pick one, which fills the tags the file is then
 * matched from (LibraryFiles::retag), so both end up the same way as a hand-typed match.
 */
class TrackSuggestions
{
    public const LIMIT = 8;

    /**
     * Library tracks whose name and artist contain the words of the hint.
     *
     * @return list<array{title: string, artist: string, album: ?string, year: ?string, detail: string}>
     */
    public function library(string $title, string $artist = ''): array
    {
        $words = fn (string $text) => array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($text))) ?: []));
        $like = fn (string $word) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';

        if ($words($title) === [] && $words($artist) === []) {
            return [];
        }

        return Track::query()->with(['artist', 'album'])
            ->where(function ($q) use ($words, $like, $title) {
                foreach ($words($title) as $word) {
                    $q->whereRaw("lower(tracks.name) like ? escape '\\'", [$like($word)]);
                }
            })
            ->when($words($artist) !== [], fn ($q) => $q->whereHas('artist', function ($a) use ($words, $like, $artist) {
                foreach ($words($artist) as $word) {
                    $a->whereRaw("lower(artists.name) like ? escape '\\'", [$like($word)]);
                }
            }))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Track $track) => [
                'title' => $track->name,
                'artist' => $track->artist?->name ?? '',
                'album' => $track->album?->name,
                'year' => $track->album?->released_at ? substr((string) $track->album->released_at, 0, 4) : null,
                'detail' => 'In your library',
            ])->all();
    }

    /**
     * MusicBrainz recordings for the hint, best match first. Network errors propagate to the caller.
     *
     * @return list<array{title: string, artist: string, album: ?string, year: ?string, detail: string}>
     */
    public function musicbrainz(string $title, string $artist = ''): array
    {
        $title = trim($title);
        if ($title === '') {
            return [];
        }

        $quote = fn (string $value) => '"'.str_replace('"', '\\"', trim($value)).'"';
        $query = 'recording:'.$quote($title).(trim($artist) !== '' ? ' AND artist:'.$quote($artist) : '');

        return collect((new MusicBrainzClient)->get('recording', ['query' => $query, 'limit' => self::LIMIT])['recordings'] ?? [])
            ->sortByDesc('score')
            ->map(function (array $recording) {
                $release = $recording['releases'][0] ?? [];
                $credit = collect($recording['artist-credit'] ?? [])->map(fn ($c) => ($c['name'] ?? '').($c['joinphrase'] ?? ''))->implode('');
                $year = substr((string) ($release['date'] ?? ''), 0, 4);

                return [
                    'title' => (string) ($recording['title'] ?? ''),
                    'artist' => $credit,
                    'album' => $release['title'] ?? null,
                    'year' => $year !== '' ? $year : null,
                    'detail' => 'MusicBrainz · '.(int) ($recording['score'] ?? 0).'% match',
                ];
            })
            ->filter(fn (array $c) => $c['title'] !== '')
            ->values()
            ->all();
    }
}
