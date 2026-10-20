<?php

namespace App\Livewire\Concerns;

use App\Domain\Device\SpotifyRouting;
use App\Events\Device\QueueUpdated;
use App\Integrations\Contracts\QueueClearInterface;
use App\Integrations\Contracts\QueueMoveInterface;
use App\Integrations\Contracts\QueueRemoveInterface;
use App\Models\Device;

/**
 * Remove, move and clear on the queue shown by a Livewire component (the player bar's Up next, the device
 * page's Up Next card). Positions are those of the list (1 = the first); `$expect` is the uri of the row
 * the user clicked, so a queue that changed in between is refused instead of editing the wrong entry.
 * A refused or failing edit is shown as `$queueError`; the view re-reads the queue after every action.
 */
trait EditsQueue
{
    public ?string $queueError = null;

    /** The device whose queue is shown; null while there is none. */
    abstract protected function queueDevice(): ?Device;

    public function removeFromQueue(int $position, ?string $expect = null): void
    {
        $this->editQueue(QueueRemoveInterface::class, fn ($d) => $d->removeFromQueue($position, $expect));
    }

    public function moveInQueue(int $position, int $to, ?string $expect = null): void
    {
        $this->editQueue(QueueMoveInterface::class, fn ($d) => $d->moveInQueue($position, $to, $expect));
    }

    public function clearQueue(): void
    {
        $this->editQueue(QueueClearInterface::class, fn ($d) => $d->clearQueue());
    }

    private function editQueue(string $contract, \Closure $apply): void
    {
        $device = $this->queueDevice();
        if ($device === null) {
            return;
        }

        try {
            $driver = SpotifyRouting::driverFor($device, $contract);

            if ($driver instanceof $contract) {
                $apply($driver);
                QueueUpdated::dispatch($device->id);
            }

            $this->queueError = null;
        } catch (\InvalidArgumentException $e) {
            $this->queueError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->queueError = 'Command failed: '.$e->getMessage();
        }
    }
}
