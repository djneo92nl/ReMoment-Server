<?php

namespace App\Jobs;

use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class EnrichTrackMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Track $track) {}

    public function handle(): void
    {
        if ($this->track->metadata()->where('key', 'enriched_at')->exists()) {
            return;
        }

        $track = $this->track->load('artist', 'album');
        $trackName = trim($track->name);
        $artistName = trim($track->artist?->name ?? '');

        if ($trackName === '' || $artistName === '') {
            return;
        }

        try {
            $this->enrichFromMusicBrainz($track, $trackName, $artistName);
        } catch (\Throwable) {
        }

        try {
            $this->fetchLyrics($track, $trackName, $artistName);
        } catch (\Throwable) {
        }

        if ($track->source === 'spotify') {
            try {
                $this->enrichFromSpotify($track, $trackName, $artistName);
            } catch (\Throwable) {
            }
        }

        $this->saveMeta($track, 'enriched_at', now()->toIso8601String(), 'string', 'system');
    }

    private function enrichFromMusicBrainz(Track $track, string $trackName, string $artistName): void
    {
        $headers = [
            'User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)',
            'Accept' => 'application/json',
        ];

        $query = sprintf(
            'recording:"%s" AND artist:"%s"',
            str_replace('"', '\\"', $trackName),
            str_replace('"', '\\"', $artistName)
        );

        $response = Http::withHeaders($headers)->get('https://musicbrainz.org/ws/2/recording', [
            'query' => $query,
            'fmt' => 'json',
            'limit' => 5,
        ]);

        if ($response->failed()) {
            return;
        }

        $recordings = $response->json('recordings', []);
        $best = collect($recordings)
            ->sortByDesc('score')
            ->first(fn ($r) => ($r['score'] ?? 0) >= 60);

        if (!$best) {
            return;
        }

        $recordingMbid = $best['id'];
        $artistMbid = $best['artist-credit'][0]['artist']['id'] ?? null;
        $releaseMbid = $best['releases'][0]['id'] ?? null;

        $this->saveMeta($track, 'mbid', $recordingMbid, 'string', 'musicbrainz');

        sleep(1);
        $recResp = Http::withHeaders($headers)->get("https://musicbrainz.org/ws/2/recording/{$recordingMbid}", [
            'inc' => 'isrcs',
            'fmt' => 'json',
        ]);
        if ($recResp->ok()) {
            $isrc = $recResp->json('isrcs.0');
            if ($isrc) {
                $this->saveMeta($track, 'isrc', $isrc, 'string', 'musicbrainz');
            }
        }

        if ($artistMbid && $track->artist) {
            sleep(1);
            $artistResp = Http::withHeaders($headers)->get("https://musicbrainz.org/ws/2/artist/{$artistMbid}", [
                'inc' => 'tags',
                'fmt' => 'json',
            ]);
            if ($artistResp->ok()) {
                $this->saveMeta($track->artist, 'mbid', $artistMbid, 'string', 'musicbrainz');

                $tags = $artistResp->json('tags', []);
                usort($tags, fn ($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));
                $genres = array_column(array_slice($tags, 0, 10), 'name');
                if (!empty($genres)) {
                    $this->saveMeta($track->artist, 'genres', json_encode($genres), 'json', 'musicbrainz');
                }

                $country = $artistResp->json('country');
                if ($country) {
                    $this->saveMeta($track->artist, 'country', $country, 'string', 'musicbrainz');
                }
            }
        }

        if ($releaseMbid && $track->album) {
            sleep(1);
            $releaseResp = Http::withHeaders($headers)->get("https://musicbrainz.org/ws/2/release/{$releaseMbid}", [
                'inc' => 'labels',
                'fmt' => 'json',
            ]);
            if ($releaseResp->ok()) {
                $this->saveMeta($track->album, 'mbid', $releaseMbid, 'string', 'musicbrainz');

                $label = $releaseResp->json('label-info.0.label.name');
                if ($label) {
                    $this->saveMeta($track->album, 'label', $label, 'string', 'musicbrainz');
                }
            }
        }
    }

    private function fetchLyrics(Track $track, string $trackName, string $artistName): void
    {
        $params = [
            'artist_name' => $artistName,
            'track_name' => $trackName,
        ];

        if ($track->album?->name) {
            $params['album_name'] = $track->album->name;
        }
        if ($track->duration) {
            $params['duration'] = $track->duration;
        }

        $response = Http::withHeaders(['User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)'])
            ->get('https://lrclib.net/api/get', $params);

        if ($response->status() === 404) {
            $this->saveMeta($track, 'lyrics_plain', '', 'string', 'lrclib');

            return;
        }

        if ($response->failed()) {
            return;
        }

        $data = $response->json();

        if ($data['instrumental'] ?? false) {
            $this->saveMeta($track, 'lyrics_plain', '', 'string', 'lrclib');

            return;
        }

        if (($plain = $data['plainLyrics'] ?? null) !== null) {
            $this->saveMeta($track, 'lyrics_plain', $plain, 'string', 'lrclib');
        }
        if (($synced = $data['syncedLyrics'] ?? null) !== null) {
            $this->saveMeta($track, 'lyrics_synced', $synced, 'string', 'lrclib');
        }
    }

    private function enrichFromSpotify(Track $track, string $trackName, string $artistName): void
    {
        $tokenService = app(SpotifyTokenService::class);
        if (!$tokenService->isConnected()) {
            return;
        }

        $api = $tokenService->makeApiClient();

        $spotifyTrackId = null;
        if ($track->external_id && str_starts_with($track->external_id, 'spotify:track:')) {
            $parts = explode(':', $track->external_id);
            $spotifyTrackId = end($parts) ?: null;
        } else {
            $results = $api->search($trackName.' '.$artistName, 'track', ['limit' => 1]);
            $spotifyTrackId = $results['tracks']['items'][0]['id'] ?? null;
        }

        if (!$spotifyTrackId) {
            return;
        }

        $spotifyTrack = $api->getTrack($spotifyTrackId);

        if (isset($spotifyTrack['popularity'])) {
            $this->saveMeta($track, 'spotify_popularity', (string) $spotifyTrack['popularity'], 'int', 'spotify');
        }
        if (isset($spotifyTrack['explicit'])) {
            $this->saveMeta($track, 'explicit', $spotifyTrack['explicit'] ? '1' : '0', 'bool', 'spotify');
        }
        $isrc = $spotifyTrack['external_ids']['isrc'] ?? null;
        if ($isrc && !$track->metadata()->where('key', 'isrc')->exists()) {
            $this->saveMeta($track, 'isrc', $isrc, 'string', 'spotify');
        }

        $artistSpotifyId = $spotifyTrack['artists'][0]['id'] ?? null;
        if ($artistSpotifyId && $track->artist) {
            $spotifyArtist = $api->getArtist($artistSpotifyId);

            $this->saveMeta($track->artist, 'spotify_id', $artistSpotifyId, 'string', 'spotify');

            if (isset($spotifyArtist['popularity'])) {
                $this->saveMeta($track->artist, 'spotify_popularity', (string) $spotifyArtist['popularity'], 'int', 'spotify');
            }

            $genres = $spotifyArtist['genres'] ?? [];
            if (!empty($genres) && !$track->artist->metadata()->where('key', 'genres')->where('source', 'musicbrainz')->exists()) {
                $this->saveMeta($track->artist, 'genres', json_encode($genres), 'json', 'spotify');
            }
        }
    }

    private function saveMeta(Model $model, string $key, string $value, string $type, string $source): void
    {
        Metadata::query()->updateOrCreate(
            [
                'metadatable_type' => $model->getMorphClass(),
                'metadatable_id' => $model->id,
                'key' => $key,
                'source' => $source,
            ],
            [
                'value' => $value,
                'type' => $type,
                'parent_id' => null,
            ]
        );
    }
}
