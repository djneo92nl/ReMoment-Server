<?php

namespace App\Integrations\Common;

use App\Models\Media\Track;
use Illuminate\Support\Collection;

/**
 * The queue edit contracts (QueueAddInterface, QueuePlayNextInterface, QueueRemoveInterface,
 * QueueMoveInterface, QueueClearInterface) on top of the server-kept DeviceQueue, for the drivers of a
 * device that has no queue of its own (UPnP renderer, ReMoment player).
 */
trait EditsDeviceQueue
{
    /** Whether this device can play the track. */
    abstract protected function canQueue(Track $track): bool;

    /** The uri `getUpNext()` reports for the track, which `$expect` is compared with. */
    abstract protected function queueUri(Track $track): ?string;

    /** Whether something is playing from the queue; adding to a queue nothing plays from would only park tracks. */
    protected function queueActive(): bool
    {
        return true;
    }

    /** Called after an edit, with the up-next position it touched at most (1 = the next track). */
    protected function queueEdited(int $position): void {}

    public function addToQueue(Collection $tracks): void
    {
        $ids = $this->queueableIds($tracks);

        $queue = DeviceQueue::for($this->device->id);
        $queue->append($ids);
        $queue->save($this->device->id);

        $this->queueEdited(1);
    }

    public function playNext(Collection $tracks): void
    {
        $ids = $this->queueableIds($tracks);

        $queue = DeviceQueue::for($this->device->id);
        $queue->insertNext($ids);
        $queue->save($this->device->id);

        $this->queueEdited(1);
    }

    public function removeFromQueue(int $position, ?string $expect = null): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $this->assertExpected($queue, $position, $expect);

        $queue->remove($position);
        $queue->save($this->device->id);

        $this->queueEdited($position);
    }

    public function moveInQueue(int $from, int $to, ?string $expect = null): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $this->assertExpected($queue, $from, $expect);

        if ($queue->at($to) === null) {
            throw new \InvalidArgumentException("There is no queue position {$to}.");
        }

        $queue->move($from, $to);
        $queue->save($this->device->id);

        $this->queueEdited(min($from, $to));
    }

    public function clearQueue(): void
    {
        $queue = DeviceQueue::for($this->device->id);
        $queue->clear();
        $queue->save($this->device->id);

        $this->queueEdited(1);
    }

    /** @return int[] */
    private function queueableIds(Collection $tracks): array
    {
        if (!$this->queueActive()) {
            throw new \InvalidArgumentException('Nothing is playing on this device; start playback first.');
        }

        $ids = $tracks->filter(fn (Track $track) => $this->canQueue($track))->pluck('id')->values()->all();

        if ($ids === []) {
            throw new \InvalidArgumentException("None of these tracks can be played on {$this->device->device_name}.");
        }

        return $ids;
    }

    private function assertExpected(DeviceQueue $queue, int $position, ?string $expect): void
    {
        $id = $queue->at($position);

        if ($id === null) {
            throw new \InvalidArgumentException("There is no queue position {$position}.");
        }

        if ($expect !== null && $this->queueUri(Track::findOrFail($id)) !== $expect) {
            throw new \InvalidArgumentException('The queue has changed; reload it and try again.');
        }
    }
}
