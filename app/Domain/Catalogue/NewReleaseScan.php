<?php

namespace App\Domain\Catalogue;

use App\Domain\Library\Enrichment;
use App\Jobs\SyncArtistCatalogue;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\Metadata;
use App\Services\ListenBrainz\ListenBrainzClient;

/**
 * New releases of the library's artists. ListenBrainz's fresh-releases feed (every artist, last and next 90 days at most) is only a
 * **trigger**: when a library artist has an album or EP release-group the catalogue does not hold yet, that artist's MusicBrainz
 * discography is synced right away (the authority, with its secondary-type filter and the link to owned albums), instead of
 * waiting for its monthly turn. Nothing is stored from the feed itself.
 */
final class NewReleaseScan
{
    public function __construct(private readonly ListenBrainzClient $client = new ListenBrainzClient) {}

    /**
     * @return list<Artist> the artists whose sync was queued (with $dispatch false: that would be queued)
     */
    public function run(int $days = 30, bool $dispatch = true): array
    {
        $releases = array_filter($this->client->freshReleases($days), fn ($r) => in_array($r['type'], ['album', 'ep'], true));
        if ($releases === []) {
            return [];
        }

        // Which of the feed's artists are in the library (artist mbid metadata), and the release-groups they already have.
        $mbids = array_values(array_unique(array_merge(...array_column($releases, 'artist_mbids'))));
        $owners = [];
        foreach (array_chunk($mbids, 400) as $chunk) {
            Metadata::where('metadatable_type', (new Artist)->getMorphClass())->where('key', 'mbid')
                ->whereIn('source', [Enrichment::MUSICBRAINZ, Metadata::MANUAL])->whereIn('value', $chunk)
                ->get(['metadatable_id', 'value'])->each(function ($m) use (&$owners) {
                    $owners[$m->value][] = (int) $m->metadatable_id;
                });
        }

        $due = [];
        foreach ($releases as $release) {
            foreach ($release['artist_mbids'] as $mbid) {
                foreach ($owners[$mbid] ?? [] as $artistId) {
                    $known = CatalogueAlbum::where('artist_id', $artistId)->where('release_group_mbid', $release['release_group_mbid'])->exists();
                    if (!$known) {
                        $due[$artistId] = true;
                    }
                }
            }
        }

        $artists = Artist::whereIn('id', array_keys($due))->get()->all();
        if ($dispatch) {
            foreach ($artists as $artist) {
                SyncArtistCatalogue::dispatch($artist);
            }
        }

        return $artists;
    }
}
