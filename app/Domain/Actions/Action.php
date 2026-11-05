<?php

namespace App\Domain\Actions;

use App\Models\Device;

/**
 * SPIKE (workstream 14.5, docs/architecture/action-layer.md): one definition of an action on a device. Not wired
 * into any route, tool or page; the generators and ActionRunner derive from it so the spike can compare the output
 * with the hand-written artefacts.
 */
abstract class Action
{
    /** Snake-case name: the MCP tool / scene step / HA service name. */
    abstract public function name(): string;

    /** OpenAPI operationId (the existing file's camelCase). */
    abstract public function operationId(): string;

    abstract public function summary(): string;

    abstract public function description(): string;

    /** Path below /api; `{device}` is the device the action is about. */
    abstract public function path(): string;

    public function method(): string
    {
        return 'post';
    }

    /** @return array<string, mixed> Laravel validation rules of the body (the path's device is not in it). */
    abstract public function rules(): array;

    /** @return array<string, mixed> defaults a rule cannot say, for the documentation and the generated snippets */
    public function defaults(): array
    {
        return [];
    }

    /** @return array<string, string> description per input field */
    public function inputDescriptions(): array
    {
        return [];
    }

    /** @return array<string, array> OpenAPI property schemas of the 200 body */
    abstract public function outputs(): array;

    /** Error codes the handler can answer with: code => HTTP status. */
    public function errors(): array
    {
        return [];
    }

    /** The device contract the driver must implement, and the capability string, or null (no driver involved). */
    public function contract(): ?string
    {
        return null;
    }

    public function capability(): ?string
    {
        return null;
    }

    /** Whether an unreachable device is refused with 503 before the handler runs. */
    public function needsReachable(): bool
    {
        return true;
    }

    public function adminOnly(): bool
    {
        return false;
    }

    /**
     * @param  mixed  $driver  the driver for `contract()` (null when the action has none)
     * @return array the 200 body
     *
     * @throws ActionException
     */
    abstract public function handle(Device $device, array $input, mixed $driver): array;
}
