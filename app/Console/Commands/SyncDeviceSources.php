<?php

namespace App\Console\Commands;

use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Models\Device;
use Illuminate\Console\Command;

class SyncDeviceSources extends Command
{
    protected $signature = 'devices:sync-sources';

    protected $description = 'Sync available sources for all capable devices';

    public function handle(): int
    {
        $devices = Device::all();

        foreach ($devices as $device) {
            try {
                $driver = $device->driver;
            } catch (\Throwable) {
                $this->line("  Skipped {$device->device_name}: driver unavailable");

                continue;
            }

            if (!($driver instanceof SourcesInterface)) {
                continue;
            }

            try {
                $sources = $driver->getSources();
            } catch (\Throwable $e) {
                $this->warn("  Failed {$device->device_name}: {$e->getMessage()}");

                continue;
            }

            $syncedIds = [];
            foreach ($sources as $position => $s) {
                $syncedIds[] = $s->sourceId;
                $apiFields = [
                    'friendly_name' => $s->friendlyName,
                    'source_type' => $s->sourceType,
                    'category' => $s->category,
                    'in_use' => $s->inUse,
                    'borrowed' => $s->borrowed,
                    'provider_jid' => $s->providerJid,
                    'provider_name' => $s->providerName,
                    'sort_order' => $position,
                ];

                $existing = $device->deviceSources()->where('source_id', $s->sourceId)->first();
                if ($existing) {
                    $existing->update($apiFields);
                } else {
                    $device->deviceSources()->create([
                        ...$apiFields,
                        'source_id' => $s->sourceId,
                        'sort_order' => $position,
                        'hidden' => $s->borrowed,
                    ]);
                }
            }

            $device->deviceSources()->whereNotIn('source_id', $syncedIds)->delete();

            $this->info("  Synced {$device->device_name}: ".count($sources).' sources');

            if ($driver instanceof MultiRoomInterface) {
                try {
                    $driver->getMultiRoomId();
                } catch (\Throwable) {
                    // Non-fatal — JID will be populated on next successful API call
                }
            }
        }

        return self::SUCCESS;
    }
}
