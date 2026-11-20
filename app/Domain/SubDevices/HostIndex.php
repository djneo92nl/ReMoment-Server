<?php

namespace App\Domain\SubDevices;

use App\Models\DeviceLink;

/**
 * Which devices are the host of a link, read once per request: `DeviceCapabilities::for()` is asked for every device
 * on a page (and for every play button), so a query per call would grow with the page. Registered scoped
 * (AppServiceProvider) and dropped whenever a link is saved or deleted.
 */
final class HostIndex
{
    /** @var array<int, true>|null */
    private ?array $hosts = null;

    public function isHost(int $deviceId): bool
    {
        $this->hosts ??= DeviceLink::query()->distinct()->pluck('host_device_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();

        return isset($this->hosts[$deviceId]);
    }
}
