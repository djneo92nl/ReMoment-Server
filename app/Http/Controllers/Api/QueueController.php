<?php

namespace App\Http\Controllers\Api;

use App\Domain\Library\QueueTracks;
use App\Events\Device\QueueUpdated;
use App\Http\Controllers\Api\Concerns\GuardsDeviceAccess;
use App\Http\Controllers\Controller;
use App\Integrations\Contracts\QueueAddInterface;
use App\Integrations\Contracts\QueueClearInterface;
use App\Integrations\Contracts\QueueMoveInterface;
use App\Integrations\Contracts\QueuePlayNextInterface;
use App\Integrations\Contracts\QueueRemoveInterface;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Editing the queue of a device: add, play next, remove, move, clear. See docs/api/queue.md. */
class QueueController extends Controller
{
    use GuardsDeviceAccess;

    /** Appends a track, album, artist or playlist to the queue, or puts it right after the current track (`next`). */
    public function store(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(QueueTracks::TYPES))],
            'id' => ['required', 'integer', fn ($attribute, $value, $fail) => QueueTracks::exists($request->input('type'), (int) $value) || $fail('The selected id is invalid.')],
            'next' => ['nullable', 'boolean'],
        ]);

        $next = $request->boolean('next');
        $tracks = QueueTracks::for($data['type'], (int) $data['id']);

        if ($next) {
            return $this->withDriver($device, QueuePlayNextInterface::class, 'queue_next', function (QueuePlayNextInterface $driver) use ($tracks, $device) {
                $driver->playNext($tracks);
                QueueUpdated::dispatch($device->id);

                return ['status' => 'ok', 'next' => true, 'tracks' => $tracks->count()];
            });
        }

        return $this->withDriver($device, QueueAddInterface::class, 'queue_add', function (QueueAddInterface $driver) use ($tracks, $device) {
            $driver->addToQueue($tracks);
            QueueUpdated::dispatch($device->id);

            return ['status' => 'ok', 'next' => false, 'tracks' => $tracks->count()];
        });
    }

    /** Removes the entry `position` places ahead (1 = the first of `up_next`); `expect` is the uri the client saw there. */
    public function destroy(Request $request, Device $device, int $position): JsonResponse
    {
        $request->validate(['expect' => ['nullable', 'string']]);

        return $this->withDriver($device, QueueRemoveInterface::class, 'queue_remove', function (QueueRemoveInterface $driver) use ($request, $position, $device) {
            $driver->removeFromQueue($position, $request->input('expect'));
            QueueUpdated::dispatch($device->id);

            return ['status' => 'ok', 'position' => $position];
        });
    }

    /** Moves the entry at `position` to position `to`. */
    public function move(Request $request, Device $device, int $position): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'integer', 'min:1', 'max:1000'],
            'expect' => ['nullable', 'string'],
        ]);

        return $this->withDriver($device, QueueMoveInterface::class, 'queue_move', function (QueueMoveInterface $driver) use ($data, $position, $device) {
            $driver->moveInQueue($position, $data['to'], $data['expect'] ?? null);
            QueueUpdated::dispatch($device->id);

            return ['status' => 'ok', 'position' => $data['to']];
        });
    }

    /** Empties the queue after the current track. */
    public function clear(Device $device): JsonResponse
    {
        return $this->withDriver($device, QueueClearInterface::class, 'queue_clear', function (QueueClearInterface $driver) use ($device) {
            $driver->clearQueue();
            QueueUpdated::dispatch($device->id);

            return ['status' => 'ok'];
        });
    }
}
