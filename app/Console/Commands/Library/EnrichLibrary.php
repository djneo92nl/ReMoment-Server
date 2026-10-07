<?php

namespace App\Console\Commands\Library;

use App\Domain\Library\Enrichment;
use App\Jobs\FetchSpotifyArtistImages;
use App\Models\Media\Artist;
use App\Services\SpotifyTokenService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class EnrichLibrary extends Command
{
    private const METADATA = 'metadata';

    private const ARTIST_IMAGES = 'artist-images';

    protected $signature = 'library:enrich
        {--only=* : Only these parts: metadata (MusicBrainz, lyrics, Spotify, Last.fm, TheAudioDB, Discogs) or artist-images (Spotify photos); default both}
        {--limit=200 : Maximum number of tracks (and of artists and albums, or artist photos) to queue}
        {--dry-run : Only count what is still missing}';

    protected $description = 'Queue enrichment for tracks, artists and albums that are still missing a source, and Spotify photos for artists without one, newest first';

    public function handle(): int
    {
        $only = $this->option('only') ?: [self::METADATA, self::ARTIST_IMAGES];

        if ($unknown = array_diff($only, [self::METADATA, self::ARTIST_IMAGES])) {
            $this->error('Unknown part: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $status = self::SUCCESS;

        if (in_array(self::METADATA, $only, true)) {
            $this->enrichMetadata();
        }

        if (in_array(self::ARTIST_IMAGES, $only, true)) {
            $status = $this->queueArtistImages();
        }

        return $status;
    }

    private function enrichMetadata(): void
    {
        $missing = Enrichment::backlog()->count();
        $entities = Enrichment::artistBacklog()->count() + Enrichment::albumBacklog()->count();

        if ($missing === 0 && $entities === 0) {
            $this->info('Nothing to enrich.');

            return;
        }

        if ($this->option('dry-run')) {
            $this->info("{$missing} track(s) are missing at least one source; {$entities} artist(s) and album(s) are missing a source.");

            return;
        }

        $limit = max(1, (int) $this->option('limit'));
        $queued = Enrichment::queueBacklog($limit);
        $queuedEntities = Enrichment::queueEntities($limit);

        $this->info("Queued enrichment for {$queued} of {$missing} track(s) and {$queuedEntities} artist and album job(s) for {$entities} record(s); the rest follows on later runs.");
    }

    private function queueArtistImages(): int
    {
        if (!app(SpotifyTokenService::class)->isConnected()) {
            // Not an error when it is only one of the parts run by default.
            $this->line('Spotify is not connected: skipping artist photos.');

            return $this->option('only') ? self::FAILURE : self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $withId = $this->imageBacklog()->whereHas('metadata', fn ($q) => $q->where('key', 'spotify_id'))->limit($limit)->pluck('artists.id');
        $withoutId = $this->imageBacklog()->whereDoesntHave('metadata', fn ($q) => $q->where('key', 'spotify_id'))
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

    /** @return Builder<Artist> artists with albums and no photo (or "no photo" verdict) yet */
    private function imageBacklog(): Builder
    {
        return Artist::query()
            ->whereDoesntHave('metadata', fn ($q) => $q->where('key', 'spotify_image'))
            ->whereHas('albums')
            ->orderBy('artists.id');
    }
}
