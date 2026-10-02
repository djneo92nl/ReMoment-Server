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

        if (!empty($item['released_at'])) {
            $albumData['released_at'] = $item['released_at'];
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

        $this->storeTags($track, $item, $server);

        if (!empty($item['released_at']) && $album->released_at === null) {
            $album->update(['released_at' => $item['released_at']]);
        }

        if (!empty($item['album_art']) && empty($album->images)) {
            $album->update(['images' => [['url' => $item['album_art']]]]);
            $this->maybeProcessArtwork($item['album_art']);
        }
    }

    /**
     * The tags and audio properties the server reports, as track metadata with source `dlna:{server}`.
     * Only values that are new or changed are written, so a rescan of a big library stays cheap.
     */
    private function storeTags(Track $track, array $item, DlnaServer $server): void
    {
        $audio = $item['audio'] ?? [];

        $tags = array_filter([
            'genres' => $item['genres'] ? [json_encode($item['genres'], JSON_UNESCAPED_UNICODE), 'json'] : null,
            'track_number' => $item['track_number'] ? [(string) $item['track_number'], 'int'] : null,
            'disc_number' => $item['disc_number'] ? [(string) $item['disc_number'], 'int'] : null,
            'album_artist' => $item['album_artist'] ? [$item['album_artist'], 'string'] : null,
            'bitrate' => isset($audio['bitrate']) ? [(string) $audio['bitrate'], 'int'] : null,
            'sample_rate' => isset($audio['sample_rate']) ? [(string) $audio['sample_rate'], 'int'] : null,
            'bit_depth' => isset($audio['bit_depth']) ? [(string) $audio['bit_depth'], 'int'] : null,
            'channels' => isset($audio['channels']) ? [(string) $audio['channels'], 'int'] : null,
            'file_size' => isset($audio['file_size']) ? [(string) $audio['file_size'], 'int'] : null,
            'mime_type' => isset($audio['mime_type']) ? [$audio['mime_type'], 'string'] : null,
        ]);

        $source = 'dlna:'.$server->id;
        $existing = $track->metadata()->where('source', $source)->pluck('value', 'key');

        foreach ($tags as $key => [$value, $type]) {
            if ($existing->get($key) !== $value) {
                Enrichment::save($track, $key, $value, $type, $source);
            }
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
