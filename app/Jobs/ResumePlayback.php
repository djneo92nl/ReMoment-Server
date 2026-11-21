<?php

namespace App\Jobs;

use App\Domain\Device\DeviceCache;
use App\Domain\Library\Normalizer;
use App\Domain\Resume\ResumeOnPlay;
use App\Integrations\Contracts\SeekInterface;
use App\Models\Device;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Seeks a device to the bookmark of the item the server just started on it (workstream 8.3). Runs a few seconds after
 * the play command: the seek only makes sense once the device reports that item, so it looks at the cached now-playing and
 * tries again a few times before giving up (the item then plays from the start).
 */
class ResumePlayback implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $deviceId,
        public string $kind,
        public int $itemId,
        public string $title,
        public int $position,
    ) {}

    public function handle(): void
    {
        $device = Device::find($this->deviceId);
        $driver = $device?->driver;

        if (!$driver instanceof SeekInterface) {
            ResumeOnPlay::done($this->deviceId);

            return;
        }

        $nowPlaying = DeviceCache::getNowPlaying($this->deviceId);
        $name = $nowPlaying?->track?->name;

        // The device has not reported the item yet (or still shows the previous one): look again in a moment.
        if ($name === null || Normalizer::track($name) !== Normalizer::track($this->title)) {
            if ($this->attempts() < $this->tries) {
                $this->release(ResumeOnPlay::DELAY);

                return;
            }
            ResumeOnPlay::done($this->deviceId);

            return;
        }

        try {
            $driver->seek($this->position);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            ResumeOnPlay::done($this->deviceId);
        }
    }

    public function failed(?\Throwable $e): void
    {
        ResumeOnPlay::done($this->deviceId);
    }
}
