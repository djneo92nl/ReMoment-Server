<?php

namespace App\Integrations\Radio\Nts;

use App\Domain\Radio\RadioSettings;
use App\Services\MetadataHttp;
use Illuminate\Support\Facades\Cache;

/**
 * NTS Radio's public JSON API (unofficial and undocumented, so every read is tolerant: a change or an outage means
 * "no data", never an error). Docs: docs/architecture/radio.md.
 */
class NtsClient
{
    private const BASE = 'https://www.nts.live/api/v2';

    /** NTS' logo, for the live channels (the API has artwork only for shows and mixtapes). */
    public const LOGO = 'https://www.nts.live/apple-touch-icon.png';

    /** The two live channels' relay streams (redirect to the current CDN). */
    public const LIVE_STREAMS = [
        '1' => 'https://stream-relay-geo.ntslive.net/stream',
        '2' => 'https://stream-relay-geo.ntslive.net/stream2',
    ];

    /** The broadcast on air now on a channel ("1" or "2"): title, genres, image, times; null when unknown. */
    public function now(string $channel): ?array
    {
        $result = collect($this->get('live', 'nts:live', 60)['results'] ?? [])->firstWhere('channel_name', $channel);
        $now = $result['now'] ?? null;

        if (!is_array($now) || empty($now['broadcast_title'])) {
            return null;
        }

        $details = $now['embeds']['details'] ?? [];

        return [
            'title' => self::text($now['broadcast_title']),
            'genres' => collect($details['genres'] ?? [])->pluck('value')->filter()->values()->all(),
            'image' => $details['media']['picture_medium'] ?? $details['media']['background_medium'] ?? null,
            'starts_at' => $now['start_timestamp'] ?? null,
            'ends_at' => $now['end_timestamp'] ?? null,
        ];
    }

    /** @return list<array{alias: string, title: string, subtitle: ?string, url: string, hls: ?string, image: ?string}> */
    public function mixtapes(): array
    {
        return collect($this->get('mixtapes', 'nts:mixtapes', 3600)['results'] ?? [])
            ->filter(fn ($m) => !empty($m['mixtape_alias']) && !empty($m['audio_stream_endpoint']))
            ->map(fn ($m) => [
                'alias' => $m['mixtape_alias'],
                'title' => self::text($m['title'] ?? $m['mixtape_alias']),
                'subtitle' => isset($m['subtitle']) ? self::text($m['subtitle']) : null,
                'url' => $m['audio_stream_endpoint'],
                'hls' => $m['audio_stream_endpoint_hls_aac'] ?? null,
                'image' => $m['media']['picture_medium'] ?? $m['media']['background_medium'] ?? null,
            ])->values()->all();
    }

    /** One page of NTS' show archive. @return array{shows: list<array<string, mixed>>, total: int} */
    public function shows(int $offset = 0, int $limit = 24): array
    {
        $data = $this->get('shows?offset='.max(0, $offset).'&limit='.max(1, min($limit, 50)), "nts:shows:{$offset}:{$limit}", 3600);

        return [
            'shows' => collect($data['results'] ?? [])->filter(fn ($s) => !empty($s['show_alias']))->map(fn ($s) => [
                'alias' => $s['show_alias'],
                'name' => self::text($s['name'] ?? $s['show_alias']),
                'description' => isset($s['description']) ? self::text(strip_tags($s['description'])) : null,
                'genres' => collect($s['genres'] ?? [])->pluck('value')->map(fn ($g) => trim($g))->filter()->values()->all(),
                'location' => $s['location_long'] ?? null,
                'image' => $s['media']['picture_medium'] ?? $s['media']['background_medium'] ?? null,
            ])->values()->all(),
            'total' => (int) ($data['metadata']['resultset']['count'] ?? 0),
        ];
    }

    /** A show's recent episodes. @return list<array{alias: string, name: string, broadcast: ?string, mixcloud: ?string, soundcloud: ?string}> */
    public function episodes(string $show, int $limit = 20): array
    {
        $data = $this->get('shows/'.rawurlencode($show).'/episodes?offset=0&limit='.max(1, min($limit, 50)), "nts:episodes:{$show}:{$limit}", 3600);

        return collect($data['results'] ?? [])->filter(fn ($e) => !empty($e['episode_alias']))->map(fn ($e) => [
            'alias' => $e['episode_alias'],
            'name' => self::text($e['name'] ?? $e['episode_alias']),
            'broadcast' => $e['broadcast'] ?? null,
            'mixcloud' => $e['mixcloud'] ?? null,
            'soundcloud' => collect($e['audio_sources'] ?? [])->firstWhere('source', 'soundcloud')['url'] ?? null,
        ])->values()->all();
    }

    /**
     * An episode's official tracklist (published some hours after the broadcast, not for every show). `offset` is
     * seconds into the show when NTS knows it, else its estimate; "Unknown Artist" rows have no artist.
     *
     * @return list<array{artist: ?string, title: string, offset: ?int}>
     */
    public function tracklist(string $show, string $episode): array
    {
        $data = $this->get('shows/'.rawurlencode($show).'/episodes/'.rawurlencode($episode).'/tracklist', "nts:tracklist:{$show}:{$episode}", 3600);

        return collect($data['results'] ?? [])->filter(fn ($t) => !empty($t['title']))->map(fn ($t) => [
            'artist' => in_array($t['artist'] ?? null, [null, '', 'Unknown Artist'], true) ? null : self::text($t['artist']),
            'title' => self::text($t['title']),
            'offset' => $t['offset'] ?? $t['offset_estimate'] ?? null,
        ])->values()->all();
    }

    /** NTS sends HTML entities in titles ("D&#039;LITE"). */
    public static function text(string $text): string
    {
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function get(string $path, string $key, int $ttl): array
    {
        if (!RadioSettings::ntsEnabled()) {
            return [];
        }

        return Cache::remember($key, $ttl, function () use ($path) {
            try {
                $response = MetadataHttp::get(self::BASE.'/'.$path);

                return $response->successful() ? (array) $response->json() : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }
}
