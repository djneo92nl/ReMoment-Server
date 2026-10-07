<?php

namespace App\Domain\Device;

use App\Integrations\Contracts\SourcesInterface;
use App\Models\Device;

/**
 * Brings a device's stored sources (`device_sources`) in line with what its
 * driver reports. Hidden/sort preferences survive, keyed by source id; a
 * newly seen source is placed at the device's position and a newly seen
 * borrowed (shared) source starts hidden.
 */
final class SourceSync
{
    /** @return int number of sources the device reports */
    public static function sync(Device $device, SourcesInterface $driver): int
    {
        $sources = $driver->getSources();
        self::store($device, $sources);

        return count($sources);
    }

    /** Stores sources a driver already returned. @param  AvailableSource[]  $sources */
    public static function store(Device $device, array $sources): void
    {
        $syncedIds = [];

        foreach ($sources as $position => $s) {
            $syncedIds[] = $s->sourceId;
            $fields = [
                'friendly_name' => $s->friendlyName,
                'source_type' => $s->sourceType,
                'category' => $s->category,
                'in_use' => $s->inUse,
                'borrowed' => $s->borrowed,
                'provider_jid' => $s->providerJid,
                'provider_name' => $s->providerName,
            ];

            $existing = $device->deviceSources()->where('source_id', $s->sourceId)->first();
            if ($existing) {
                $existing->update($fields);
            } else {
                $device->deviceSources()->create([
                    ...$fields,
                    'source_id' => $s->sourceId,
                    'sort_order' => $position,
                    'hidden' => $s->borrowed,
                ]);
            }
        }

        $device->deviceSources()->whereNotIn('source_id', $syncedIds)->delete();
    }
}
