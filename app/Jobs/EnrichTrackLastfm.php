<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Track;
use App\Services\Lastfm\LastfmInfoClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrichTrackLastfm implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Track $track) {}

    public function uniqueId(): string
    {
        return (string) $this->track->id;
    }

    public function handle(LastfmInfoClient $lastfm): void
    {
        $track = $this->track->load('artist');

        if (!LastfmInfoClient::enabled() || !Enrichment::eligible($track) || Enrichment::isDone($track, Enrichment::LASTFM)) {
            return;
        }

        $info = $lastfm->get('track.getinfo', ['artist' => trim($track->artist->name), 'track' => trim($track->name)])['track'] ?? null;

        if ($info !== null) {
            Enrichment::saveStats($track, $info);
            Enrichment::saveTags($track, LastfmInfoClient::tagNames($info['toptags']['tag'] ?? null), Enrichment::LASTFM);
        }

        Enrichment::markDone($track, Enrichment::LASTFM);
    }
}
