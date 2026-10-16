<?php

namespace App\Livewire;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Health\Heartbeat;
use App\Domain\Health\QueueInspector;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;

class SystemHealth extends Component
{
    /** Heartbeats older than this are shown as down (scheduler beats every 60s). */
    private const STALE_SECONDS = 180;

    public function render()
    {
        return view('livewire.system-health', [
            'broker' => $this->broker(),
            'supervisor' => $this->supervisor(),
            'scheduler' => $this->heartbeat(Heartbeat::SCHEDULER),
            'queueWorker' => $this->heartbeat(Heartbeat::QUEUE),
            'queue' => $this->queue(),
            'pendingJobs' => app(QueueInspector::class)->pending(),
            'failedJobs' => app(QueueInspector::class)->failed(),
            'devices' => $this->devices(),
        ]);
    }

    private function broker(): array
    {
        $host = config('mqtt.host');
        $port = config('mqtt.port');

        $socket = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
        }

        return [
            'ok' => (bool) $socket,
            'address' => "{$host}:{$port}",
            'error' => $socket ? null : ($errstr ?: 'Connection failed'),
            'ws_url' => config('mqtt.ws_url') ?: 'ws://{page host}:9001',
        ];
    }

    /** Whether a supervisord process is running in this container (Linux /proc; null where that can't be told). */
    private function supervisor(): ?bool
    {
        if (!is_dir('/proc/1')) {
            return null;
        }

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmdline = @file_get_contents($file);
            if ($cmdline !== false && str_contains($cmdline, 'supervisord')) {
                return true;
            }
        }

        return false;
    }

    private function heartbeat(string $key): array
    {
        $last = Heartbeat::last($key);

        return [
            'ok' => $last !== null && $last->diffInSeconds(now()) < self::STALE_SECONDS,
            'last' => $last,
        ];
    }

    private function queue(): array
    {
        try {
            $pending = Queue::size();
        } catch (\Throwable) {
            $pending = null;
        }

        try {
            $failedCount = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $failedCount = null;
        }

        return ['pending' => $pending, 'failed' => $failedCount];
    }

    public function retryFailed(string $id, QueueInspector $inspector): void
    {
        $inspector->retry($id);
        $this->toast('Job put back on the queue.');
    }

    public function retryAllFailed(QueueInspector $inspector): void
    {
        $inspector->retry('all');
        $this->toast('All failed jobs put back on the queue.');
    }

    public function forgetFailed(string $id, QueueInspector $inspector): void
    {
        $inspector->forget($id);
    }

    public function flushFailed(QueueInspector $inspector): void
    {
        $inspector->flushFailed();
        $this->toast('Failed jobs cleared.');
    }

    public function restartWorkers(QueueInspector $inspector): void
    {
        $inspector->restartWorkers();
        $this->toast('Workers told to restart after their current job.');
    }

    public function queueMissingEnrichment(QueueInspector $inspector): void
    {
        $records = $inspector->queueMissingEnrichment();
        $this->toast($records > 0 ? "Looked at {$records} tracks and queued what they still miss." : 'Nothing left to fetch.');
    }

    private function toast(string $message): void
    {
        session()->flash('health-success', $message);
    }

    private function devices(): array
    {
        return Device::orderBy('device_name')->get()->map(function (Device $device) {
            $lastSeen = DeviceCache::getLastSeen($device->id);

            return [
                'device' => $device,
                'listener_running' => DeviceCache::isListenerRunning($device->id),
                'state' => DeviceCache::getState($device->id),
                'last_seen' => $lastSeen ?: null,
                'volume' => Volume::getVolume($device->id),
            ];
        })->all();
    }
}
