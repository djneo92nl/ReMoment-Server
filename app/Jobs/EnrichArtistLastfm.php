<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Artist;
use App\Services\Lastfm\LastfmInfoClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrichArtistLastfm implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Artist $artist) {}

    public function uniqueId(): string
    {
        return (string) $this->artist->id;
    }

    public function handle(LastfmInfoClient $lastfm): void
    {
        $artist = $this->artist;
        $name = trim($artist->name);

        if (!LastfmInfoClient::enabled() || $name === '' || Enrichment::isDone($artist, Enrichment::LASTFM)) {
            return;
        }

        $info = $lastfm->get('artist.getinfo', ['artist' => $name])['artist'] ?? null;

        if ($info !== null) {
            if ($bio = LastfmInfoClient::cleanSummary($info['bio']['summary'] ?? null)) {
                Enrichment::save($artist, 'bio', $bio, 'string', Enrichment::LASTFM);
            }

            $similar = array_values(array_filter(array_column($info['similar']['artist'] ?? [], 'name')));
            if ($similar) {
                Enrichment::save($artist, 'similar_artists', json_encode($similar), 'json', Enrichment::LASTFM);
            }

            Enrichment::saveStats($artist, $info['stats'] ?? []);
            Enrichment::saveTags($artist, LastfmInfoClient::tagNames($info['tags']['tag'] ?? null), Enrichment::LASTFM);
        }

        Enrichment::markDone($artist, Enrichment::LASTFM);
    }
}
