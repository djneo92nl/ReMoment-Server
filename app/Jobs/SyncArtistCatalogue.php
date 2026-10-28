<?php

namespace App\Jobs;

use App\Domain\Alerts\Alerts;
use App\Domain\Catalogue\ArtistCatalogueSync;
use App\Domain\Health\Queues;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Artist;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** An artist's MusicBrainz discography (album and EP release-groups), linked to the library's albums. */
class SyncArtistCatalogue implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public const ALERT_KIND = 'catalogue.sync';

    /** Failed artists before the alert is raised: one bad artist is not news, a pattern is. */
    private const ALERT_AFTER = 3;

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
        return [new RateLimited('musicbrainz')];
    }

    public function handle(): void
    {
        (new ArtistCatalogueSync)->sync($this->artist);
        Alerts::resolve(self::ALERT_KIND, 'musicbrainz');
    }

    public function failed(\Throwable $e): void
    {
        ArtistCatalogueSync::markError($this->artist);

        if (\App\Models\Media\CatalogueArtist::where('status', 'error')->count() >= self::ALERT_AFTER) {
            Alerts::raise(self::ALERT_KIND, 'musicbrainz', 'warning', 'Discography sync failing',
                'MusicBrainz discographies could not be synced for several artists: '.$e->getMessage());
        }
    }
}
