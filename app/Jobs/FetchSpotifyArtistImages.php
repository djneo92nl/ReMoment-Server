<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Domain\Library\Normalizer;
use App\Domain\Library\SpotifyImages;
use App\Models\Media\Artist;
use App\Services\SpotifyTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use SpotifyWebAPI\SpotifyWebAPIException;

/**
 * Stores Spotify's photo of each artist as `spotify_image` metadata (source spotify). Artists
 * with a known `spotify_id` are fetched in one batch request; others are looked up by exact
 * name first (and get their `spotify_id`). An artist without a photo gets an empty value, so
 * it isn't asked again.
 */
class FetchSpotifyArtistImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Spotify's batch endpoint takes at most 50 ids. */
    public const BATCH = 50;

    /** Smallest width a stored photo should have; covers are shown at 320px at most. */
    private const MIN_WIDTH = 320;

    /** Releases by the limiter don't count as attempts; only thrown exceptions do (as in EnrichesFromSource). */
    public int $tries = 0;

    public int $maxExceptions = 4;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function middleware(): array
    {
        return [new RateLimited('spotify')];
    }

    public bool $deleteWhenMissingModels = true;

    /** @param  list<int>  $artistIds */
    public function __construct(public readonly array $artistIds) {}

    public function handle(SpotifyTokenService $tokenService): void
    {
        if (!$tokenService->isConnected()) {
            return;
        }

        $api = $tokenService->makeApiClient();
        $artists = Artist::query()->whereIn('id', $this->artistIds)->get();

        $bySpotifyId = [];
        foreach ($artists as $artist) {
            $spotifyId = $artist->metaValue('spotify_id') ?: $this->search($api, $artist);

            if ($spotifyId === null) {
                $this->save($artist, null);

                continue;
            }

            $bySpotifyId[$spotifyId][] = $artist;
        }

        foreach (array_chunk(array_keys($bySpotifyId), self::BATCH) as $ids) {
            foreach ($this->fetchArtists($api, $ids) as $spotifyArtist) {
                foreach ($bySpotifyId[$spotifyArtist['id'] ?? ''] ?? [] as $artist) {
                    $this->save($artist, SpotifyImages::pick($spotifyArtist['images'] ?? [], self::MIN_WIDTH));
                }
            }
        }
    }

    /**
     * The batch endpoint (GET /artists?ids=) is removed for new Development Mode apps (February 2026):
     * then ask for each artist alone, skipping one that fails. Other failures still fail the job.
     *
     * @param  list<string>  $ids
     * @return list<array>
     */
    private function fetchArtists($api, array $ids): array
    {
        try {
            return $api->getArtists($ids)['artists'] ?? [];
        } catch (SpotifyWebAPIException $e) {
            if (!in_array($e->getCode(), [400, 403, 404, 410], true)) {
                throw $e;
            }
        }

        $artists = [];
        foreach ($ids as $id) {
            try {
                $artists[] = $api->getArtist($id);
            } catch (SpotifyWebAPIException $e) {
                if (!in_array($e->getCode(), [403, 404, 410], true)) {
                    throw $e;
                }
            }
        }

        return $artists;
    }

    /** Spotify's id for an artist whose name matches exactly (as the library compares names). */
    private function search($api, Artist $artist): ?string
    {
        $results = $api->search('artist:'.$artist->name, 'artist', ['limit' => 10]);

        foreach ($results['artists']['items'] ?? [] as $candidate) {
            if (Normalizer::artist($candidate['name'] ?? null) === Normalizer::artist($artist->name) && !empty($candidate['id'])) {
                Enrichment::save($artist, 'spotify_id', $candidate['id'], 'string', Enrichment::SPOTIFY);

                return $candidate['id'];
            }
        }

        return null;
    }

    private function save(Artist $artist, ?string $url): void
    {
        Enrichment::save($artist, 'spotify_image', $url ?? '', 'url', Enrichment::SPOTIFY);
    }
}
