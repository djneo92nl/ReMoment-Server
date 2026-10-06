<?php

namespace App\Console\Commands;

use App\Jobs\FetchSpotifyArtistImages;
use App\Models\Media\Artist;
use App\Services\SpotifyTokenService;
use Illuminate\Console\Command;

class FetchArtistImages extends Command
{
    protected $signature = 'library:artist-images {--limit=1000 : Most artists to queue per run} {--dry-run : Only count}';

    protected $description = 'Queue Spotify photos for artists that have none yet (artists with a known Spotify id first)';

    public function handle(SpotifyTokenService $spotify): int
    {
        if (!$spotify->isConnected()) {
            $this->error('Spotify is not connected.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $withId = $this->backlog()->whereHas('metadata', fn ($q) => $q->where('key', 'spotify_id'))->limit($limit)->pluck('artists.id');
        $withoutId = $this->backlog()->whereDoesntHave('metadata', fn ($q) => $q->where('key', 'spotify_id'))
            ->limit(max(0, $limit - $withId->count()))->pluck('artists.id');

        $this->info("{$withId->count()} artist(s) with a Spotify id, {$withoutId->count()} to look up by name.");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        foreach ($withId->chunk(FetchSpotifyArtistImages::BATCH) as $chunk) {
            FetchSpotifyArtistImages::dispatch($chunk->values()->all());
        }

        // One search request per artist, so smaller jobs.
        foreach ($withoutId->chunk(20) as $chunk) {
            FetchSpotifyArtistImages::dispatch($chunk->values()->all());
        }

        return self::SUCCESS;
    }

    private function backlog()
    {
        return Artist::query()
            ->whereDoesntHave('metadata', fn ($q) => $q->where('key', 'spotify_image'))
            ->whereHas('albums')
            ->orderBy('artists.id');
    }
}
