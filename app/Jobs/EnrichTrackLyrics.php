<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Track;
use App\Services\MetadataHttp;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnrichTrackLyrics implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Track $track) {}

    public function uniqueId(): string
    {
        return (string) $this->track->id;
    }

    public function handle(): void
    {
        $track = $this->track->load('artist', 'album');

        if (Enrichment::isDone($track, Enrichment::LRCLIB) || !Enrichment::eligible($track)) {
            return;
        }

        $params = [
            'artist_name' => trim($track->artist->name),
            'track_name' => trim($track->name),
        ];

        if ($track->album?->name) {
            $params['album_name'] = $track->album->name;
        }
        if ($track->duration) {
            $params['duration'] = $track->duration;
        }

        // Rate limits and outages are retried (MetadataHttp); any other failure is treated like "nothing found".
        $response = MetadataHttp::get('https://lrclib.net/api/get', $params);

        if ($response->status() === 404) {
            $this->none($track);

            return;
        }

        if ($response->failed()) {
            $this->none($track);

            return;
        }

        $data = $response->json();

        if ($data['instrumental'] ?? false) {
            $this->none($track);

            return;
        }

        if (($plain = $data['plainLyrics'] ?? null) !== null) {
            Enrichment::save($track, 'lyrics_plain', $plain, 'string', Enrichment::LRCLIB);
        }
        if (($synced = $data['syncedLyrics'] ?? null) !== null) {
            Enrichment::save($track, 'lyrics_synced', $synced, 'string', Enrichment::LRCLIB);
        }

        Enrichment::markDone($track, Enrichment::LRCLIB);
    }

    private function none(Track $track): void
    {
        Enrichment::save($track, 'lyrics_plain', '', 'string', Enrichment::LRCLIB);
        Enrichment::markDone($track, Enrichment::LRCLIB);
    }
}
