<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Device\QueueItem;
use App\Domain\Library\DlnaTrackLookup;

/**
 * "Up next" from the device's own play queue. An item only carries the stream URL it was started
 * with (the device keeps no name, artist or cover for a DLNA track), so each is looked up in the
 * library by that URL; an item the library doesn't know is left out.
 */
trait PlayQueueControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    /** @return QueueItem[] */
    public function getUpNext(int $limit = 20): array
    {
        // Offsets count from the playing item (0); the ones ahead of it are positive.
        $queue = $this->deviceApiClient()->getStrict('BeoZone/Zone/PlayQueue', ['offset' => 1, 'count' => max(1, $limit)])['playQueue'] ?? [];

        $items = [];
        foreach ($queue['playQueueItem'] ?? [] as $item) {
            $url = $item['track']['dlna']['url'] ?? null;
            $track = $url ? DlnaTrackLookup::byUrl($url) : null;

            if ($track === null) {
                continue;
            }

            $items[] = new QueueItem(
                name: $track->name,
                artist: $track->artist?->name,
                album: $track->album?->name,
                image: LibraryArtwork::coverUrl($track->images) ?? LibraryArtwork::coverUrl($track->album?->images),
                duration: $track->duration,
                uri: $url,
            );
        }

        return array_slice($items, 0, $limit);
    }
}
