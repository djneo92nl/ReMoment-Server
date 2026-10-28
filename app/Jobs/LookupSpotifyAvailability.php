<?php

namespace App\Jobs;

use App\Domain\Catalogue\SpotifyAvailability;
use App\Domain\Health\Queues;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Artist;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;

/** Records which of an artist's MusicBrainz release-groups Spotify has (one artist-albums listing, then local matching). */
class LookupSpotifyAvailability implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Artist $artist)
    {
        $this->onQueue(Queues::CATALOGUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->artist->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('spotify')];
    }

    public function handle(SpotifyAvailability $spotify): void
    {
        $spotify->lookupArtist($this->artist);
    }

    /**
     * Queues the lookup when an artist's discography is looked at and some answer is missing or old: at most once an
     * hour per artist, and nothing without a Spotify connection.
     */
    public static function queueFor(Artist $artist): void
    {
        $spotify = app(SpotifyAvailability::class);

        if ($spotify->enabled() && Cache::add('catalogue:spotify-lookup:'.$artist->id, 1, 3600) && $spotify->staleRows($artist)->isNotEmpty()) {
            self::dispatch($artist);
        }
    }
}
