<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Domain\Library\Normalizer;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Album;
use App\Services\Discogs\DiscogsClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** An album's Discogs release: format, styles, genres and credits (needs DISCOGS_TOKEN). */
class EnrichAlbumDiscogs implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    private const MAX_CREDITS = 40;

    public function __construct(public readonly Album $album) {}

    public function uniqueId(): string
    {
        return (string) $this->album->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('discogs')];
    }

    public function handle(DiscogsClient $discogs): void
    {
        $album = $this->album->load('artist');

        if (!DiscogsClient::enabled() || !Enrichment::albumEligible($album) || Enrichment::isDone($album, Enrichment::DISCOGS)) {
            return;
        }

        $releaseId = $this->findRelease($discogs, $album);
        $release = $releaseId ? $discogs->get("releases/{$releaseId}") : null;

        if ($release !== null) {
            $this->store($album, $release);
        }

        Enrichment::markDone($album, Enrichment::DISCOGS);
    }

    /** The first search hit whose "Artist - Title" is this album; a loose hit would put another album's credits here. */
    private function findRelease(DiscogsClient $discogs, Album $album): ?int
    {
        $results = $discogs->get('database/search', [
            'type' => 'release',
            'artist' => trim($album->artist->name),
            'release_title' => trim($album->name),
            'per_page' => 10,
        ])['results'] ?? [];

        $artistKey = Normalizer::artist($album->artist->name);
        $albumKey = Normalizer::album($album->name);

        foreach ($results as $result) {
            [$artist, $title] = array_pad(explode(' - ', (string) ($result['title'] ?? ''), 2), 2, '');

            if (Normalizer::album($title) === $albumKey && Normalizer::artist($this->withoutDisambiguation($artist)) === $artistKey) {
                return (int) $result['id'];
            }
        }

        return null;
    }

    private function store(Album $album, array $release): void
    {
        $save = Enrichment::saver($album, Enrichment::DISCOGS);

        $save('discogs_id', (string) $release['id']);
        $save('discogs_url', $release['uri'] ?? null);

        $format = $release['formats'][0] ?? null;
        if ($format) {
            $save('format', implode(', ', array_filter([$format['name'] ?? null, ...($format['descriptions'] ?? [])])));
        }

        // Styles are the finer genres, so they come first.
        $genres = array_merge($release['styles'] ?? [], $release['genres'] ?? []);
        if ($genres) {
            $save('genres', json_encode($genres, JSON_UNESCAPED_UNICODE), 'json');
        }

        $credits = [];
        foreach ($release['extraartists'] ?? [] as $person) {
            $role = trim(explode('[', (string) ($person['role'] ?? ''))[0]);
            $name = $this->withoutDisambiguation((string) ($person['name'] ?? ''));

            if ($role !== '' && $name !== '') {
                $credits[strtolower($role).'|'.$name] ??= ['role' => strtolower($role), 'name' => $name];
            }
        }
        if ($credits) {
            $save('credits', json_encode(array_slice(array_values($credits), 0, self::MAX_CREDITS), JSON_UNESCAPED_UNICODE), 'json');
        }
    }

    /** Discogs numbers namesakes: "Prince (2)". */
    private function withoutDisambiguation(string $name): string
    {
        return trim(preg_replace('/\s*\(\d+\)$/', '', $name));
    }
}
