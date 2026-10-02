<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Track;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class EnrichTrackMusicBrainz implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    private bool $requested = false;

    public function __construct(public readonly Track $track) {}

    public function uniqueId(): string
    {
        return (string) $this->track->id;
    }

    /** MusicBrainz allows about one request per second per client; see the `musicbrainz` limiter. */
    public function middleware(): array
    {
        return [new RateLimited('musicbrainz')];
    }

    public function handle(): void
    {
        $track = $this->track->load('artist', 'album');

        if (Enrichment::isDone($track, Enrichment::MUSICBRAINZ) || !Enrichment::eligible($track)) {
            return;
        }

        $this->enrich($track);

        Enrichment::markDone($track, Enrichment::MUSICBRAINZ);
    }

    private function enrich(Track $track): void
    {
        $query = sprintf(
            'recording:"%s" AND artist:"%s"',
            str_replace('"', '\\"', trim($track->name)),
            str_replace('"', '\\"', trim($track->artist->name))
        );

        $response = $this->get('recording', ['query' => $query, 'limit' => 5]);

        $best = collect($response?->json('recordings', []) ?? [])
            ->sortByDesc('score')
            ->first(fn ($r) => ($r['score'] ?? 0) >= 60);

        if (!$best) {
            return;
        }

        $recordingMbid = $best['id'];
        $artistMbid = $best['artist-credit'][0]['artist']['id'] ?? null;
        $releaseMbid = $best['releases'][0]['id'] ?? null;

        Enrichment::save($track, 'mbid', $recordingMbid, 'string', Enrichment::MUSICBRAINZ);

        $recording = $this->get("recording/{$recordingMbid}", ['inc' => 'isrcs']);
        if ($isrc = $recording?->json('isrcs.0')) {
            Enrichment::save($track, 'isrc', $isrc, 'string', Enrichment::MUSICBRAINZ);
        }

        if ($artistMbid && $track->artist) {
            $artist = $this->get("artist/{$artistMbid}", ['inc' => 'tags']);
            if ($artist) {
                Enrichment::save($track->artist, 'mbid', $artistMbid, 'string', Enrichment::MUSICBRAINZ);

                $tags = $artist->json('tags', []);
                usort($tags, fn ($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));
                $genres = array_column(array_slice($tags, 0, 10), 'name');
                if (!empty($genres)) {
                    Enrichment::save($track->artist, 'genres', json_encode($genres), 'json', Enrichment::MUSICBRAINZ);
                }

                if ($country = $artist->json('country')) {
                    Enrichment::save($track->artist, 'country', $country, 'string', Enrichment::MUSICBRAINZ);
                }
            }
        }

        if ($releaseMbid && $track->album) {
            $release = $this->get("release/{$releaseMbid}", ['inc' => 'labels']);
            if ($release) {
                Enrichment::save($track->album, 'mbid', $releaseMbid, 'string', Enrichment::MUSICBRAINZ);

                if ($label = $release->json('label-info.0.label.name')) {
                    Enrichment::save($track->album, 'label', $label, 'string', Enrichment::MUSICBRAINZ);
                }
            }
        }
    }

    /**
     * One MusicBrainz call, a second apart from the previous one. A rate limit or outage throws so the
     * whole job is retried; any other failure (404, bad query) returns null, i.e. "nothing there".
     */
    private function get(string $path, array $query): ?Response
    {
        if ($this->requested) {
            Sleep::for(1)->second();
        }
        $this->requested = true;

        $response = Http::withHeaders([
            'User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)',
            'Accept' => 'application/json',
        ])->get("https://musicbrainz.org/ws/2/{$path}", $query + ['fmt' => 'json']);

        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }

        return $response->ok() ? $response : null;
    }
}
