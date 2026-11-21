<?php

namespace App\Jobs;

use App\Domain\Device\DeviceScan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A device scan started from the admin API (`DeviceScan::start()`); the finds land in the discovery inbox. */
class ScanForDevices implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    /** @param  list<string>  $hosts */
    public function __construct(public array $hosts = []) {}

    public function handle(DeviceScan $scan): void
    {
        try {
            DeviceScan::finish($scan->run($this->hosts));
        } catch (\Throwable $e) {
            DeviceScan::finish(null, $e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        DeviceScan::finish(null, $e->getMessage());
    }
}
