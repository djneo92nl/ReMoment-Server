<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Domain\Library\Normalizer;
use App\Models\Media\Artist;
use App\Services\SpotifyTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    public int $tries = 3;

    public int $backoff = 60;

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
            $spotifyId = $artist->metadata()->where('key', 'spotify_id')->value('value') ?: $this->search($api, $artist);

            if ($spotifyId === null) {
                $this->save($artist, null);

                continue;
            }

            $bySpotifyId[$spotifyId][] = $artist;
        }

        foreach (array_chunk(array_keys($bySpotifyId), self::BATCH) as $ids) {
            foreach ($api->getArtists($ids)['artists'] ?? [] as $spotifyArtist) {
                foreach ($bySpotifyId[$spotifyArtist['id'] ?? ''] ?? [] as $artist) {
                    $this->save($artist, $this->pickImage($spotifyArtist['images'] ?? []));
                }
            }
        }
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

    /** The smallest image that is still at least MIN_WIDTH wide, else the largest. */
    private function pickImage(array $images): ?string
    {
        usort($images, fn ($a, $b) => ($a['width'] ?? 0) <=> ($b['width'] ?? 0));

        foreach ($images as $image) {
            if (($image['width'] ?? 0) >= self::MIN_WIDTH && !empty($image['url'])) {
                return $image['url'];
            }
        }

        return $images ? end($images)['url'] ?? null : null;
    }

    private function save(Artist $artist, ?string $url): void
    {
        Enrichment::save($artist, 'spotify_image', $url ?? '', 'url', Enrichment::SPOTIFY);
    }
}
