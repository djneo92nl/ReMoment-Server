<?php

namespace App\Services\Dlna;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Library\Enrichment;
use App\Domain\Library\LibraryIdentity;
use App\Jobs\ProcessArtwork;
use App\Models\DlnaServer;
use App\Models\Media\Metadata;
use App\Models\Media\Track;

class DlnaLibraryScanner
{
    private int $tracksImported = 0;

    private array $visitedContainers = [];

    /** @var array<string, true> */
    private array $dispatchedArtwork = [];

    public function scanServer(DlnaServer $server, ?callable $progress = null): int
    {
        $this->tracksImported = 0;
        $this->visitedContainers = [];
        $this->dispatchedArtwork = [];

        $client = new DlnaContentDirectoryClient($server->control_url);
        $this->browseContainer($client, '0', $server, $progress);

        $server->update(['last_scanned_at' => now()]);

        // Enrich a bounded batch of the new tracks now; the daily library:enrich works off the rest.
        Enrichment::queueBacklog(Enrichment::SCAN_BATCH);

        return $this->tracksImported;
    }

    private function browseContainer(
        DlnaContentDirectoryClient $client,
        string $objectId,
        DlnaServer $server,
        ?callable $progress = null,
    ): void {
        if (isset($this->visitedContainers[$objectId])) {
            return;
        }
        $this->visitedContainers[$objectId] = true;

        $result = $client->browseChildren($objectId);

        foreach ($result['items'] as $item) {
            $this->importTrack($item, $server);
            $this->tracksImported++;
            if ($progress) {
                ($progress)($this->tracksImported, $item['title']);
            }
        }

        foreach ($result['containers'] as $container) {
            $this->browseContainer($client, $container['id'], $server, $progress);
        }
    }

    private function importTrack(array $item, DlnaServer $server): void
    {
        if (empty($item['url'])) {
            return;
        }

        $artistName = $item['artist'] ?: 'Unknown Artist';
        $albumName = $item['album'] ?: 'Unknown Album';

        // One record per artist/album/track whatever the source (LibraryIdentity).
        $artist = LibraryIdentity::artist($artistName, 'dlna');

        $albumData = [];
        if (!empty($item['album_art'])) {
            $albumData['images'] = [['url' => $item['album_art']]];
        }

        $album = LibraryIdentity::album($artist, $albumName, 'dlna', $albumData);

        if ($album->wasRecentlyCreated) {
            $this->maybeProcessArtwork($item['album_art'] ?? null);
        }

        $track = LibraryIdentity::track(
            $artist,
            $album,
            $item['title'] ?: 'Unknown Track',
            $server->id.':'.$item['id'],
            'dlna',
            ['duration' => $item['duration']],
        );

        Metadata::updateOrCreate(
            [
                'metadatable_type' => Track::class,
                'metadatable_id' => $track->id,
                'key' => 'dlna_url',
                // Per server: a merged track can have a stream on more than one.
                'source' => 'dlna:'.$server->id,
            ],
            [
                'value' => $item['url'],
                'type' => 'url',
            ],
        );

        if (!empty($item['album_art']) && empty($album->images)) {
            $album->update(['images' => [['url' => $item['album_art']]]]);
            $this->maybeProcessArtwork($item['album_art']);
        }
    }

    private function maybeProcessArtwork(?string $url): void
    {
        if (!$url || isset($this->dispatchedArtwork[$url]) || ArtworkCache::has($url)) {
            return;
        }

        $this->dispatchedArtwork[$url] = true;
        ProcessArtwork::dispatch($url);
    }
}
