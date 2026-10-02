<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Album;
use App\Services\Lastfm\LastfmInfoClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrichAlbumLastfm implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Album $album) {}

    public function uniqueId(): string
    {
        return (string) $this->album->id;
    }

    public function handle(LastfmInfoClient $lastfm): void
    {
        $album = $this->album->load('artist');

        if (!LastfmInfoClient::enabled() || !Enrichment::albumEligible($album) || Enrichment::isDone($album, Enrichment::LASTFM)) {
            return;
        }

        $info = $lastfm->get('album.getinfo', ['artist' => trim($album->artist->name), 'album' => trim($album->name)])['album'] ?? null;

        if ($info !== null) {
            if ($summary = LastfmInfoClient::cleanSummary($info['wiki']['summary'] ?? null)) {
                Enrichment::save($album, 'lastfm_summary', $summary, 'string', Enrichment::LASTFM);
            }

            Enrichment::saveStats($album, $info);
            Enrichment::saveTags($album, LastfmInfoClient::tagNames($info['tags']['tag'] ?? null), Enrichment::LASTFM);
        }

        Enrichment::markDone($album, Enrichment::LASTFM);
    }
}
