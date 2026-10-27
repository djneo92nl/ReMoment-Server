<?php

namespace App\Livewire\Concerns;

use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Domain\Playback\NotMovableException;
use App\Domain\Playback\PlaybackMover;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * "Move playback to…" for a Livewire component that has one device it is about (the sticky player bar):
 * the targets to pick (view: livewire.partials.player-move) and the move itself (docs/api/playback-move.md).
 */
trait MovesPlayback
{
    /** What the last move did, shown in the picker: the target's id and name, or an error. */
    public ?array $moved = null;

    public ?string $moveError = null;

    /** The device whose playback moves. */
    abstract protected function moveSourceDevice(): Device;

    public function movePlayback(int $toDeviceId): void
    {
        $this->moved = null;
        $this->moveError = null;

        $from = $this->moveSourceDevice();
        $to = Device::findOrFail($toDeviceId);

        try {
            $result = app(PlaybackMover::class)->move($from, $to);
            $this->moved = ['id' => $to->id, 'name' => $to->device_name, 'skipped' => $result['skipped']];
        } catch (NotMovableException|NotPlayableException|PlaybackFailedException $e) {
            $this->moveError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->moveError = "Could not move playback: {$e->getMessage()}";
        }
    }

    /**
     * The devices to move to: every visible, reachable device but this one and the Spotify virtual device
     * (Spotify moves to the speaker it is mapped to).
     *
     * @return Collection<int, Device>
     */
    protected function moveTargets(): Collection
    {
        $from = $this->moveSourceDevice();

        return Device::where('hidden', false)->where('id', '!=', $from->id)->orderBy('device_name')->get()
            ->reject(fn (Device $d) => SpotifyRouting::isSpotify($d) || $d->liveState() === State::Unreachable)
            ->values();
    }
}
