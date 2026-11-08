<?php

namespace App\Livewire\Developer;

use App\Domain\Alerts\Alerts;
use App\Domain\Developer\CallTimings;
use App\Domain\Device\Cache\Modes;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Health\QueueInspector;
use App\Domain\Storage\Proxies;
use App\Models\Device;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

/** /settings/developer: the transcode cache, one device's cache, the alert check and the queue workers. */
class Maintenance extends Component
{
    public ?int $deviceId = null;

    public ?string $message = null;

    public function purgeProfile(string $profile): void
    {
        $disk = Proxies::disk();

        if (!Proxies::known($profile)) {
            return;
        }

        $disk->deleteDirectory(config('proxies.directory').'/'.$profile);
        $this->message = "Deleted the {$profile} copies.";
    }

    public function purgeAll(): void
    {
        Proxies::disk()->deleteDirectory(config('proxies.directory'));
        Proxies::disk()->deleteDirectory('devtones');
        $this->message = 'Deleted every transcode and test signal.';
    }

    public function prune(): void
    {
        $this->message = 'Pruned '.Proxies::prune().' old copies (limit '.config('proxies.max_mb').' MB).';
    }

    public function clearDeviceCache(): void
    {
        if (!$device = $this->deviceId ? Device::find($this->deviceId) : null) {
            $this->message = 'Choose a device first.';

            return;
        }

        DeviceCache::forget($device->id);
        DeviceCache::forgetNowPlaying($device->id);
        Modes::forget($device->id);
        Volume::forget($device->id);
        CallTimings::forget($device->id);
        $this->message = "Cleared the cache of {$device->device_name}; its listener fills it again.";
    }

    public function checkAlerts(): void
    {
        Artisan::call('alerts:check');
        $this->message = 'alerts:check: '.trim(Artisan::output() ?: 'done');
    }

    public function testAlert(): void
    {
        Alerts::raise('dev_test', 'dev', 'info', 'Test alert', 'Raised from /settings/developer.');
        $this->message = 'Raised a test alert (MQTT remoment/alerts/dev_test).';
    }

    public function resolveTestAlert(): void
    {
        $this->message = Alerts::resolve('dev_test', 'dev') ? 'Test alert resolved.' : 'No test alert was active.';
    }

    public function restartWorkers(QueueInspector $inspector): void
    {
        $inspector->restartWorkers();
        $this->message = 'Queue workers told to restart after their current job.';
    }

    /** @return array<int, array{name: string, count: int, bytes: int}> */
    private function proxyUsage(): array
    {
        $disk = Proxies::disk();
        $rows = [];

        foreach (array_keys(Proxies::profiles()) as $name) {
            $files = $disk->allFiles(config('proxies.directory').'/'.$name);
            $rows[] = ['name' => $name, 'count' => count($files), 'bytes' => array_sum(array_map(fn ($f) => $disk->size($f), $files))];
        }

        return array_values(array_filter($rows, fn (array $r) => $r['count'] > 0));
    }

    public function render()
    {
        $usage = $this->proxyUsage();

        return view('livewire.developer.maintenance', [
            'usage' => $usage,
            'totalBytes' => array_sum(array_column($usage, 'bytes')),
            'limit' => (int) config('proxies.max_mb'),
            'devices' => Device::orderBy('device_name')->get(),
            'testAlertActive' => Alerts::isActive('dev_test', 'dev'),
        ]);
    }
}
