<?php

namespace App\Services\Dlna;

use App\Models\DlnaServer;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;

class DlnaLibraryScanner
{
    private int $tracksImported = 0;

    public function scanServer(DlnaServer $server, ?callable $progress = null): int
    {
        $this->tracksImported = 0;

        $client = new DlnaContentDirectoryClient($server->control_url);
        $this->browseContainer($client, '0', $server, $progress);

        $server->update(['last_scanned_at' => now()]);

        return $this->tracksImported;
    }

    private function browseContainer(
        DlnaContentDirectoryClient $client,
        string $objectId,
        DlnaServer $server,
        ?callable $progress = null,
    ): void {
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

        $artist = Artist::firstOrCreate(
            ['name' => $artistName, 'source' => 'dlna'],
        );

        $albumData = ['source' => 'dlna'];
        if (! empty($item['album_art'])) {
            $albumData['images'] = [['url' => $item['album_art']]];
        }

        $album = Album::firstOrCreate(
            ['artist_id' => $artist->id, 'name' => $albumName, 'source' => 'dlna'],
            $albumData,
        );

        $externalId = $server->id.':'.$item['id'];

        $track = Track::updateOrCreate(
            ['external_id' => $externalId, 'source' => 'dlna'],
            [
                'album_id' => $album->id,
                'artist_id' => $artist->id,
                'name' => $item['title'] ?: 'Unknown Track',
                'duration' => $item['duration'],
            ],
        );

        Metadata::updateOrCreate(
            [
                'metadatable_type' => Track::class,
                'metadatable_id' => $track->id,
                'key' => 'dlna_url',
            ],
            [
                'value' => $item['url'],
                'source' => 'dlna:'.$server->id,
                'type' => 'url',
            ],
        );

        if (! empty($item['album_art']) && empty($album->images)) {
            $album->update(['images' => [['url' => $item['album_art']]]]);
        }
    }
}
