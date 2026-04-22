<?php

namespace App\Console\Commands;

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

            $device->deviceSources()->delete();

            $device->deviceSources()->createMany(
                array_map(fn ($s) => [
                    'source_id' => $s->sourceId,
                    'friendly_name' => $s->friendlyName,
                    'source_type' => $s->sourceType,
                    'category' => $s->category,
                    'in_use' => $s->inUse,
                    'borrowed' => $s->borrowed,
                    'provider_jid' => $s->providerJid,
                    'provider_name' => $s->providerName,
                ], $sources)
            );

            $this->info("  Synced {$device->device_name}: ".count($sources).' sources');
        }

        return self::SUCCESS;
    }
}
