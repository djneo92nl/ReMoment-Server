<?php

namespace App\Domain\Actions\Definitions;

use App\Domain\Actions\Action;
use App\Domain\Actions\ActionException;
use App\Domain\Device\State;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Domain\Playback\NotMovableException;
use App\Domain\Playback\PlaybackMover;
use App\Models\Device;

/** `POST /devices/{device}/move` as a definition (compare DeviceController::move). */
final class MovePlaybackAction extends Action
{
    public function name(): string
    {
        return 'move_playback';
    }

    public function operationId(): string
    {
        return 'movePlayback';
    }

    public function summary(): string
    {
        return 'Move what plays on this device to another device (capability move_playback)';
    }

    public function description(): string
    {
        return 'Move what a device plays to another device: library tracks continue at the same position with the same queue, radio restarts the station, Spotify is handed over with Spotify\'s own transfer. The playing device is stopped afterwards unless stop_source is false.';
    }

    public function path(): string
    {
        return '/devices/{device}/move';
    }

    public function rules(): array
    {
        return [
            'to_device_id' => ['required', 'integer', 'exists:devices,id'],
            'stop_source' => ['nullable', 'boolean'],
        ];
    }

    public function defaults(): array
    {
        return ['stop_source' => true];
    }

    public function inputDescriptions(): array
    {
        return ['to_device_id' => 'the device to continue on', 'stop_source' => 'stop the source device afterwards (default true)'];
    }

    public function outputs(): array
    {
        return [
            'status' => ['type' => 'string'],
            'to' => ['type' => 'string', 'description' => 'Name of the target device'],
            'kind' => ['type' => 'string', 'enum' => ['library', 'radio', 'spotify']],
            'queued' => ['type' => 'integer'],
            'skipped' => ['type' => 'integer'],
            'position' => ['type' => 'integer', 'description' => 'Seconds the target was asked to continue from'],
        ];
    }

    public function errors(): array
    {
        return ['not_movable' => 422, 'not_playable' => 422, 'unreachable' => 503, 'driver_error' => 502];
    }

    public function handle(Device $device, array $input, mixed $driver): array
    {
        if ((int) $input['to_device_id'] === $device->id) {
            throw new ActionException('invalid', 'Playback is already on that device.');
        }

        $target = Device::findOrFail($input['to_device_id']);

        if ($target->state === State::Unreachable) {
            throw new ActionException('unreachable', 'Device is not reachable.', 503);
        }

        try {
            $result = app(PlaybackMover::class)->move($device, $target, (bool) ($input['stop_source'] ?? true));
        } catch (NotMovableException $e) {
            throw new ActionException('not_movable', $e->getMessage());
        } catch (NotPlayableException $e) {
            throw new ActionException('not_playable', $e->getMessage());
        } catch (PlaybackFailedException $e) {
            throw new ActionException('driver_error', $e->getMessage(), 502);
        }

        return ['status' => 'ok', 'to' => $target->device_name] + $result;
    }
}
