<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Domain\Library\SpotifyUri;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Track;
use App\Services\SpotifyTokenService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrichTrackSpotify implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Track $track) {}

    public function uniqueId(): string
    {
        return (string) $this->track->id;
    }

    public function handle(SpotifyTokenService $tokenService): void
    {
        $track = $this->track->load('artist', 'album');

        // Not connected: leave it unmarked so it is picked up once Spotify is connected.
        if (Enrichment::isDone($track, Enrichment::SPOTIFY) || !Enrichment::eligible($track) || !$tokenService->isConnected()) {
            return;
        }

        $api = $tokenService->makeApiClient();

        $spotifyTrackId = SpotifyUri::trackId($track->external_id);
        if ($spotifyTrackId === null) {
            $results = $api->search(trim($track->name).' '.trim($track->artist->name), 'track', ['limit' => 1]);
            $spotifyTrackId = $results['tracks']['items'][0]['id'] ?? null;
        }

        if ($spotifyTrackId) {
            $this->store($track, $api, $spotifyTrackId);
        }

        Enrichment::markDone($track, Enrichment::SPOTIFY);
    }

    private function store(Track $track, $api, string $spotifyTrackId): void
    {
        $spotifyTrack = $api->getTrack($spotifyTrackId);

        if (isset($spotifyTrack['popularity'])) {
            Enrichment::save($track, 'spotify_popularity', (string) $spotifyTrack['popularity'], 'int', Enrichment::SPOTIFY);
        }
        if (isset($spotifyTrack['explicit'])) {
            Enrichment::save($track, 'explicit', $spotifyTrack['explicit'] ? '1' : '0', 'bool', Enrichment::SPOTIFY);
        }
        $isrc = $spotifyTrack['external_ids']['isrc'] ?? null;
        if ($isrc && !$track->metadata()->where('key', 'isrc')->exists()) {
            Enrichment::save($track, 'isrc', $isrc, 'string', Enrichment::SPOTIFY);
        }

        $artistSpotifyId = $spotifyTrack['artists'][0]['id'] ?? null;
        if (!$artistSpotifyId) {
            return;
        }

        $spotifyArtist = $api->getArtist($artistSpotifyId);

        Enrichment::save($track->artist, 'spotify_id', $artistSpotifyId, 'string', Enrichment::SPOTIFY);

        if (isset($spotifyArtist['popularity'])) {
            Enrichment::save($track->artist, 'spotify_popularity', (string) $spotifyArtist['popularity'], 'int', Enrichment::SPOTIFY);
        }

        $genres = $spotifyArtist['genres'] ?? [];
        if (!empty($genres) && !$track->artist->metadata()->where('key', 'genres')->where('source', 'musicbrainz')->exists()) {
            Enrichment::save($track->artist, 'genres', json_encode($genres), 'json', Enrichment::SPOTIFY);
        }
    }
}
