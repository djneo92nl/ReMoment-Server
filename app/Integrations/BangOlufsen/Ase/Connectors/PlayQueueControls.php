<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Device\QueueItem;
use App\Domain\Library\DlnaTrackLookup;
use App\Integrations\Common\AddsToDlnaQueue;
use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

/**
 * "Up next" from the device's own play queue. An item only carries the stream URL it was started
 * with (the device keeps no name, artist or cover for a DLNA track), so each is looked up in the
 * library by that URL; an item the library doesn't know is left out.
 */
trait PlayQueueControls
{
    use AddsToDlnaQueue;

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

    /**
     * Empties the speaker's play queue, item by item through the ids it lists (its own `delete` links), so
     * what it played before cannot play on after the library content. Best effort: a queue that cannot be
     * read or an item that cannot be removed never blocks playback.
     */
    public function clearPlayQueue(): void
    {
        try {
            $queue = $this->deviceApiClient()->getStrict('BeoZone/Zone/PlayQueue')['playQueue'] ?? [];
            $total = (int) ($queue['total'] ?? 0);

            if ($total === 0) {
                return;
            }

            // Offsets count from the playing item, so the queue starts at a negative one.
            $items = $this->deviceApiClient()->getStrict('BeoZone/Zone/PlayQueue', ['offset' => (int) ($queue['startOffset'] ?? 0), 'count' => $total])['playQueue']['playQueueItem'] ?? [];
        } catch (\Throwable) {
            return;
        }

        foreach ($items as $item) {
            if (!empty($item['id'])) {
                try {
                    $this->deviceApiClient()->deleteStrict('BeoZone/Zone/PlayQueue/'.$item['id']);
                } catch (\Throwable) {
                    // keep clearing the rest
                }
            }
        }
    }

    /**
     * Puts tracks right after the playing one. The queue's `insert` link (`PlayQueue?id=plid-N`) inserts before
     * item N, so each track goes in before the item that followed the playing one; with nothing after it they are
     * appended. Not verified on hardware yet (docs/architecture/refactor-hardware-check.md).
     */
    public function playNext(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \InvalidArgumentException('None of these tracks have a playable DLNA stream.');
        }

        $before = $this->queueEntries(1)[0]['id'] ?? null;

        foreach ($playable as $track) {
            if ($before === null) {
                $this->enqueueDlna($track->getDlnaUrl(), $track);

                continue;
            }

            $this->deviceApiClient()->postStrict('BeoZone/Zone/PlayQueue?id='.$before, ['playQueueItem' => [
                'behaviour' => 'impulsive',
                'track' => ['dlna' => ['url' => $track->getDlnaUrl()]],
            ]]);
        }
    }

    public function removeFromQueue(int $position, ?string $expect = null): void
    {
        $entry = $this->queueEntryAt($position, $expect);

        $this->deviceApiClient()->deleteStrict('BeoZone/Zone/PlayQueue/'.$entry['id']);
    }

    /**
     * Moves an item through the queue's `move` link (`PlayQueue/plid-N?id=<move before this id>`), so it needs the
     * item it ends up in front of: moving to the very end is not known to work and is refused. Not verified on
     * hardware yet.
     */
    public function moveInQueue(int $from, int $to, ?string $expect = null): void
    {
        $entry = $this->queueEntryAt($from, $expect);
        $target = $this->queueEntryAt($to);

        if ($entry['id'] === $target['id']) {
            return;
        }

        if ($to < $from) {
            $before = $target['id'];
        } else {
            $entries = $this->queueEntries(1000);
            $index = array_search($target['id'], array_column($entries, 'id'), true);
            $before = $entries[$index + 1]['id'] ?? null;

            if ($before === null) {
                throw new UnsupportedOperationException('This speaker cannot move an item to the end of its queue.');
            }
        }

        $this->deviceApiClient()->putStrict('BeoZone/Zone/PlayQueue/'.$entry['id'].'?id='.$before);
    }

    /** Empties the queue after the playing item. */
    public function clearQueue(): void
    {
        foreach ($this->queueEntries(1000) as $entry) {
            $this->deviceApiClient()->deleteStrict('BeoZone/Zone/PlayQueue/'.$entry['id']);
        }
    }

    /**
     * The items after the playing one as the speaker lists them (all of them, also the ones the library doesn't know).
     *
     * @return array<int, array{id: string, url: ?string}>
     */
    private function queueEntries(int $count): array
    {
        $items = $this->deviceApiClient()->getStrict('BeoZone/Zone/PlayQueue', ['offset' => 1, 'count' => max(1, $count)])['playQueue']['playQueueItem'] ?? [];

        return collect($items)
            ->filter(fn ($item) => !empty($item['id']))
            ->map(fn ($item) => ['id' => $item['id'], 'url' => $item['track']['dlna']['url'] ?? null])
            ->values()
            ->all();
    }

    /**
     * The entry of up-next position `$position`: positions count what getUpNext() lists, which leaves out items the
     * library doesn't know. `$expect` is the url listed there.
     *
     * @return array{id: string, url: ?string}
     */
    private function queueEntryAt(int $position, ?string $expect = null): array
    {
        $known = array_values(array_filter($this->queueEntries(1000), fn ($entry) => $entry['url'] && DlnaTrackLookup::byUrl($entry['url']) !== null));
        $entry = $position >= 1 ? ($known[$position - 1] ?? null) : null;

        if ($entry === null) {
            throw new \InvalidArgumentException("There is no queue position {$position}.");
        }

        if ($expect !== null && $entry['url'] !== $expect) {
            throw new \InvalidArgumentException('The queue has changed; reload it and try again.');
        }

        return $entry;
    }
}
