<?php

namespace App\Jobs;

use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class EnrichArtistLastfm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly Artist $artist) {}

    public function handle(): void
    {
        $apiKey = config('lastfm.api_key');
        $artistName = trim($this->artist->name);

        if (!$apiKey || $artistName === '') {
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

        if ($response->failed()) {
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
    }

    private function saveMeta(string $key, string $value, string $type): void
    {
        Metadata::query()->updateOrCreate(
            [
                'metadatable_type' => $this->artist->getMorphClass(),
                'metadatable_id' => $this->artist->id,
                'key' => $key,
                'source' => 'lastfm',
            ],
            [
                'value' => $value,
                'type' => $type,
                'parent_id' => null,
            ]
        );
    }
}
