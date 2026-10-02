<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Artist;
use App\Services\MusicBrainz\MusicBrainzClient;
use App\Services\Wikipedia\WikipediaClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** An artist's MusicBrainz details (genres, type, area, life span, links) and Wikipedia summary, once per artist. */
class EnrichArtistMusicBrainz implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Artist $artist) {}

    public function uniqueId(): string
    {
        return (string) $this->artist->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('musicbrainz')];
    }

    public function handle(WikipediaClient $wikipedia): void
    {
        $artist = $this->artist;
        $mbid = $artist->metadata()->where('key', 'mbid')->where('source', Enrichment::MUSICBRAINZ)->value('value');

        if (!$mbid || Enrichment::isDone($artist, Enrichment::MUSICBRAINZ)) {
            return;
        }

        $data = (new MusicBrainzClient)->get("artist/{$mbid}", ['inc' => 'genres+tags+url-rels']);

        if ($data !== null) {
            $this->store($artist, $data, $wikipedia);
        }

        Enrichment::markDone($artist, Enrichment::MUSICBRAINZ);
    }

    private function store(Artist $artist, array $data, WikipediaClient $wikipedia): void
    {
        $save = fn (string $key, ?string $value, string $type = 'string') => $value !== null && $value !== ''
            ? Enrichment::save($artist, $key, $value, $type, Enrichment::MUSICBRAINZ) : null;

        // Curated genres first; the free-form tags only when there are none.
        $names = collect($data['genres'] ?? [])->sortByDesc('count')->pluck('name');
        if ($names->isEmpty()) {
            $names = collect($data['tags'] ?? [])->sortByDesc('count')->pluck('name')->take(10);
        }
        if ($names->isNotEmpty()) {
            $save('genres', json_encode($names->values()->all(), JSON_UNESCAPED_UNICODE), 'json');
        }

        $save('country', $data['country'] ?? null);
        $save('area', $data['area']['name'] ?? null);
        $save('artist_type', isset($data['type']) ? strtolower($data['type']) : null);
        $save('begin_date', $data['life-span']['begin'] ?? null);
        $save('end_date', $data['life-span']['end'] ?? null);

        $links = MusicBrainzClient::links($data['relations'] ?? []);
        if ($links) {
            $save('links', json_encode($links), 'json');
        }

        if (($qid = MusicBrainzClient::wikidataId($links)) && ($summary = $wikipedia->summaryForWikidata($qid))) {
            Enrichment::save($artist, 'wikidata_id', $qid, 'string', 'wikipedia');
            Enrichment::save($artist, 'wikipedia_extract', $summary['extract'], 'string', 'wikipedia');
            if ($summary['url']) {
                Enrichment::save($artist, 'wikipedia_url', $summary['url'], 'string', 'wikipedia');
            }
        }
    }
}
