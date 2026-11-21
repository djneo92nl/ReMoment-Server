<?php

namespace App\Domain\Ripper;

use App\Services\MusicBrainz\MusicBrainzClient;

/**
 * The releases MusicBrainz knows for a disc, to choose from (docs/architecture/node-ripper.md): by the MusicBrainz disc
 * id the node computed from the TOC, else by the TOC itself (a "fuzzy" match, a different pressing of the same album).
 * Only reads; a rate limit or outage throws, so the queued job that calls it is retried.
 */
final class DiscLookup
{
    private const INC = 'artist-credits+recordings+labels+release-groups+discids';

    /**
     * @return array{lookup: string, candidates: list<array<string, mixed>>} `lookup` is discid, toc or none
     */
    public static function candidates(string $discId, ?string $toc, int $tracks, ?MusicBrainzClient $mb = null): array
    {
        $mb ??= new MusicBrainzClient;

        $data = $mb->get('discid/'.rawurlencode($discId), ['inc' => self::INC]);
        $lookup = 'discid';

        if (empty($data['releases']) && $toc) {
            $data = $mb->get('discid/-', ['toc' => $toc, 'cdstubs' => 'no', 'inc' => self::INC]);
            $lookup = 'toc';
        }

        $candidates = [];
        foreach ($data['releases'] ?? [] as $release) {
            if ($c = self::normalize($release, $discId, $tracks)) {
                $candidates[] = $c;
            }
        }

        return ['lookup' => $candidates === [] ? 'none' : $lookup, 'candidates' => $candidates];
    }

    /** One release by id (a release chosen by hand, e.g. from the physical item), or null when MusicBrainz does not know it. @return array<string, mixed>|null */
    public static function release(string $mbid, string $discId, int $tracks, ?MusicBrainzClient $mb = null): ?array
    {
        $data = ($mb ?? new MusicBrainzClient)->get('release/'.rawurlencode($mbid), ['inc' => self::INC]);

        return $data ? self::normalize($data, $discId, $tracks) : null;
    }

    /**
     * A release as the card and the job need it. The medium is the one that carries the disc id, else the one whose
     * track count matches the disc in the drive; a release without such a medium is no candidate.
     *
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>|null
     */
    public static function normalize(array $release, string $discId, int $tracks): ?array
    {
        if (empty($release['id'])) {
            return null;
        }

        $media = array_values($release['media'] ?? []);
        $medium = null;
        foreach ($media as $m) {
            if (collect($m['discs'] ?? [])->contains(fn ($d) => ($d['id'] ?? null) === $discId)) {
                $medium = $m;
                break;
            }
        }
        $medium ??= collect($media)->first(fn ($m) => count($m['tracks'] ?? []) === $tracks && strtolower((string) ($m['format'] ?? 'cd')) !== 'vinyl');
        if ($medium === null || count($medium['tracks'] ?? []) !== $tracks) {
            return null;
        }

        $info = $release['label-info'][0] ?? [];

        return [
            'release_mbid' => strtolower($release['id']),
            'release_group_mbid' => $release['release-group']['id'] ?? null,
            'artist' => self::credit($release['artist-credit'] ?? []),
            'title' => (string) ($release['title'] ?? ''),
            'year' => isset($release['date']) && preg_match('/^\d{4}/', $release['date']) ? (int) substr($release['date'], 0, 4) : null,
            'label' => $info['label']['name'] ?? null,
            'catalogue_number' => $info['catalog-number'] ?? null,
            'country' => $release['country'] ?? null,
            'barcode' => preg_replace('/\D/', '', (string) ($release['barcode'] ?? '')) ?: null,
            'disc' => (int) ($medium['position'] ?? 1),
            'discs' => count($media),
            'track_count' => $tracks,
            'tracks' => array_values(array_map(fn ($t, $i) => [
                'number' => (int) ($t['position'] ?? $i + 1),
                'title' => (string) ($t['title'] ?? $t['recording']['title'] ?? ''),
                'artist' => self::credit($t['artist-credit'] ?? $t['recording']['artist-credit'] ?? []) ?: null,
                'length' => isset($t['length']) ? (int) round($t['length'] / 1000) : null,
            ], $medium['tracks'], array_keys($medium['tracks']))),
        ];
    }

    /** @param  list<array<string, mixed>>  $credit */
    private static function credit(array $credit): string
    {
        return trim(implode('', array_map(fn ($c) => ($c['name'] ?? $c['artist']['name'] ?? '').($c['joinphrase'] ?? ''), $credit)));
    }
}
