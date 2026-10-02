<?php

namespace App\Jobs;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Library\Enrichment;
use App\Domain\Library\ReleaseDate;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Album;
use App\Services\MusicBrainz\MusicBrainzClient;
use App\Services\Wikipedia\WikipediaClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;

/**
 * An album's MusicBrainz release details (label, catalog number, type, date, genres), its Wikipedia summary and,
 * when it has no image, its cover from the Cover Art Archive; once per album.
 */
class EnrichAlbumMusicBrainz implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Album $album) {}

    public function uniqueId(): string
    {
        return (string) $this->album->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('musicbrainz')];
    }

    public function handle(WikipediaClient $wikipedia): void
    {
        $album = $this->album;
        $mbid = $album->metadata()->where('key', 'mbid')->where('source', Enrichment::MUSICBRAINZ)->value('value');

        if (!$mbid || Enrichment::isDone($album, Enrichment::MUSICBRAINZ)) {
            return;
        }

        $mb = new MusicBrainzClient;

        if ($release = $mb->get("release/{$mbid}", ['inc' => 'labels+release-groups+genres'])) {
            $this->storeRelease($album, $release, $mb, $wikipedia);
        }

        if (empty($album->images)) {
            $this->fillCover($album, $mbid);
        }

        Enrichment::markDone($album, Enrichment::MUSICBRAINZ);
    }

    private function storeRelease(Album $album, array $release, MusicBrainzClient $mb, WikipediaClient $wikipedia): void
    {
        $save = fn (string $key, ?string $value, string $type = 'string') => $value !== null && $value !== ''
            ? Enrichment::save($album, $key, $value, $type, Enrichment::MUSICBRAINZ) : null;

        $labelInfo = collect($release['label-info'] ?? []);
        $save('label', $labelInfo->pluck('label.name')->filter()->first());
        $save('catalog_number', $labelInfo->pluck('catalog-number')->filter()->first());
        $save('barcode', $release['barcode'] ?? null);
        $save('country', $release['country'] ?? null);
        $save('release_date', $release['date'] ?? null);

        $media = collect($release['media'] ?? []);
        if ($media->isNotEmpty()) {
            $save('disc_count', (string) $media->count(), 'int');
            $save('track_count', (string) $media->sum('track-count'), 'int');
        }

        if (!$album->released_at && ($date = ReleaseDate::parse($release['date'] ?? null))) {
            $album->update(['released_at' => $date]);
        }

        $group = $release['release-group'] ?? [];
        $save('release_type', isset($group['primary-type']) ? strtolower($group['primary-type']) : null);
        if (!empty($group['secondary-types'])) {
            $save('secondary_types', json_encode(array_map('strtolower', $group['secondary-types'])), 'json');
        }
        $save('release_group_mbid', $group['id'] ?? null);

        $genres = collect($release['genres'] ?? []);

        // The release group is where Wikipedia is linked from (and it has its own genres).
        if (!empty($group['id']) && ($groupData = $mb->get("release-group/{$group['id']}", ['inc' => 'genres+url-rels']))) {
            $genres = $genres->concat($groupData['genres'] ?? []);

            $links = MusicBrainzClient::links($groupData['relations'] ?? []);
            if (($qid = MusicBrainzClient::wikidataId($links)) && ($summary = $wikipedia->summaryForWikidata($qid))) {
                Enrichment::save($album, 'wikipedia_extract', $summary['extract'], 'string', 'wikipedia');
                if ($summary['url']) {
                    Enrichment::save($album, 'wikipedia_url', $summary['url'], 'string', 'wikipedia');
                }
            }
        }

        $names = $genres->sortByDesc('count')->pluck('name')->unique()->values();
        if ($names->isNotEmpty()) {
            $save('genres', json_encode($names->all(), JSON_UNESCAPED_UNICODE), 'json');
        }
    }

    /** Uses the front cover from the Cover Art Archive for an album that has no image, and queues its processing. */
    private function fillCover(Album $album, string $releaseMbid): void
    {
        $response = Http::withHeaders(['User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)'])
            ->get("https://coverartarchive.org/release/{$releaseMbid}");

        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }

        $front = collect($response->ok() ? $response->json('images', []) : [])->first(fn ($image) => $image['front'] ?? false);
        $url = $front['thumbnails']['1200'] ?? $front['thumbnails']['large'] ?? $front['image'] ?? null;

        if (!$url) {
            return;
        }

        $url = preg_replace('~^http://~', 'https://', $url);
        $album->update(['images' => [['url' => $url]]]);

        if (!ArtworkCache::has($url)) {
            ProcessArtwork::dispatch($url);
        }
    }
}
