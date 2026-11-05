<?php

namespace App\Domain\Actions\Definitions;

use App\Domain\Actions\Action;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Models\Device;

/** `POST /devices/{device}/sources/activate` as a definition (compare DeviceController::activateSource). */
final class ActivateSourceAction extends Action
{
    public function name(): string
    {
        return 'activate_source';
    }

    public function operationId(): string
    {
        return 'activateSource';
    }

    public function summary(): string
    {
        return 'Switch to a source (capability source_activation)';
    }

    public function description(): string
    {
        return 'Switch a device to one of its input sources (Spotify, line-in, TV, CD…).';
    }

    public function path(): string
    {
        return '/devices/{device}/sources/activate';
    }

    public function rules(): array
    {
        return ['source_id' => ['required', 'string']];
    }

    public function inputDescriptions(): array
    {
        return ['source_id' => 'source_id to switch to'];
    }

    public function outputs(): array
    {
        return ['status' => ['type' => 'string'], 'source_id' => ['type' => 'string']];
    }

    public function contract(): ?string
    {
        return SourceActivationInterface::class;
    }

    public function capability(): ?string
    {
        return 'source_activation';
    }

    public function needsReachable(): bool
    {
        return false;
    }

    public function handle(Device $device, array $input, mixed $driver): array
    {
        $driver->activateSource($input['source_id']);

        return ['status' => 'ok', 'source_id' => $input['source_id']];
    }
}
