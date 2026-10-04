<?php

namespace App\Console\Commands;

use App\Domain\Device\SourceSync;
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
                $count = SourceSync::sync($device, $driver);
            } catch (\Throwable $e) {
                $this->warn("  Failed {$device->device_name}: {$e->getMessage()}");

                continue;
            }

            $this->info("  Synced {$device->device_name}: {$count} sources");

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
