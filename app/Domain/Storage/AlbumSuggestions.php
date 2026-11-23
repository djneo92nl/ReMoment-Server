<?php

namespace App\Domain\Storage;

use App\Models\Media\Album;
use App\Services\MetadataHttp;
use App\Services\MusicBrainz\MusicBrainzClient;

/**
 * What an album could be, for the album tag editor: MusicBrainz releases matching the album and artist, and the albums
 * MusicBrainz knows of the artist (marked when the library has them), each with its Cover Art Archive picture.
 * Picking one only fills the form; nothing is written until the editor saves. Network errors propagate to the caller.
 */
class AlbumSuggestions
{
    public const LIMIT = 8;

    private const COVER_HOST = 'https://coverartarchive.org/';

    /**
     * @return array{releases: list<array<string, mixed>>, artist: ?array{name: string, albums: list<array<string, mixed>>}}
     */
    public function lookup(string $artist, string $album): array
    {
        $mb = new MusicBrainzClient;
        $releases = $this->releases($mb, $artist, $album);

        return ['releases' => $releases, 'artist' => $this->artist($mb, $artist)];
    }

    /** Releases only (the cover search): a fresh client per call. @return list<array<string, mixed>> */
    public function searchReleases(string $artist, string $album): array
    {
        return $this->releases(new MusicBrainzClient, $artist, $album);
    }

    /** @return list<array<string, mixed>> */
    private function releases(MusicBrainzClient $mb, string $artist, string $album): array
    {
        $quote = fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], trim($v)).'"';

        $releases = [];
        if (trim($album) !== '') {
            $query = 'release:'.$quote($album).(trim($artist) !== '' ? ' AND artist:'.$quote($artist) : '');
            $releases = collect($mb->get('release', ['query' => $query, 'limit' => self::LIMIT])['releases'] ?? [])
                ->filter(fn ($r) => !empty($r['id']))
                ->map(function (array $r) {
                    $credit = collect($r['artist-credit'] ?? [])->map(fn ($c) => ($c['name'] ?? '').($c['joinphrase'] ?? ''))->implode('');
                    $year = substr((string) ($r['date'] ?? ''), 0, 4);

                    return [
                        'title' => (string) ($r['title'] ?? ''),
                        'artist' => $credit,
                        'year' => $year !== '' ? $year : null,
                        'detail' => collect([$r['country'] ?? null, isset($r['track-count']) ? $r['track-count'].' tracks' : null, (int) ($r['score'] ?? 0).'% match'])->filter()->implode(' · '),
                        'cover' => self::COVER_HOST.'release/'.$r['id'].'/front-500',
                    ];
                })->values()->all();
        }

        return $releases;
    }

    /** The cover bytes of a Cover Art Archive URL (only that host), or null when there is none. */
    public function coverBytes(string $url): ?string
    {
        if (!str_starts_with($url, self::COVER_HOST)) {
            return null;
        }
        $response = MetadataHttp::get($url);

        return $response->ok() ? $response->body() : null;
    }

    /** @return ?array{name: string, albums: list<array<string, mixed>>} */
    private function artist(MusicBrainzClient $mb, string $name): ?array
    {
        if (trim($name) === '') {
            return null;
        }
        $hit = $mb->searchArtists($name, 1)[0] ?? null;
        if (!$hit || $hit['score'] < 90) {
            return null;
        }

        $inLibrary = Album::query()->whereHas('artist', fn ($q) => $q->where('name', $hit['name']))->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $albums = collect($mb->releaseGroups($hit['id']) ?? [])
            ->sortBy(fn ($g) => $g['date'] ?? '9999')
            ->take(30)
            ->map(fn ($g) => [
                'title' => $g['title'],
                'year' => $g['date'] ? substr($g['date'], 0, 4) : null,
                'detail' => ucfirst($g['type']).(in_array(mb_strtolower($g['title']), $inLibrary, true) ? ' · in your library' : ''),
                'cover' => self::COVER_HOST.'release-group/'.$g['id'].'/front-500',
            ])->values()->all();

        return ['name' => $hit['name'], 'albums' => $albums];
    }
}
