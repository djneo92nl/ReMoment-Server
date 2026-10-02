<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Artist;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;

class EnrichArtistLastfm implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Artist $artist) {}

    public function uniqueId(): string
    {
        return (string) $this->artist->id;
    }

    public function handle(): void
    {
        $apiKey = config('lastfm.api_key');
        $artistName = trim($this->artist->name);

        if (!$apiKey || $artistName === '' || $this->artist->metadata()->where('source', Enrichment::LASTFM)->exists()) {
            return;
        }

        $response = Http::withHeaders(['User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)'])
            ->get('https://ws.audioscrobbler.com/2.0/', [
                'method' => 'artist.getinfo',
                'artist' => $artistName,
                'api_key' => $apiKey,
                'format' => 'json',
                'autocorrect' => 1,
            ]);

        // Rate limits and outages are retried; anything else (an unknown artist) is just "no data".
        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }

        if ($response->failed()) {
            Enrichment::markDone($this->artist, Enrichment::LASTFM);

            return;
        }

        $summary = $response->json('artist.bio.summary');
        if ($summary) {
            $bio = trim(preg_replace('/\s*<a href="[^"]*">Read more on Last\.fm<\/a>\.?\s*$/', '', $summary));
            if ($bio !== '') {
                $this->saveMeta('bio', $bio, 'string');
            }
        }

        $similar = collect($response->json('artist.similar.artist', []))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        if (!empty($similar)) {
            $this->saveMeta('similar_artists', json_encode($similar), 'json');
        }

        Enrichment::markDone($this->artist, Enrichment::LASTFM);
    }

    private function saveMeta(string $key, string $value, string $type): void
    {
        Enrichment::save($this->artist, $key, $value, $type, Enrichment::LASTFM);
    }
}
