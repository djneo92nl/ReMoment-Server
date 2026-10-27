<?php

namespace App\Livewire;

use App\Domain\Backup\BackupStatus;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Health\Heartbeat;
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
        // A dead scheduler can't run alerts:check; the open health page can notice it (a cache read).
        try {
            app(\App\Domain\Alerts\AlertChecks::class)->scheduler();
        } catch (\Throwable) {
        }

        return view('livewire.system-health', [
            'broker' => $this->broker(),
            'supervisor' => $this->supervisor(),
            'scheduler' => $this->heartbeat(Heartbeat::SCHEDULER),
            'queueWorker' => $this->heartbeat(Heartbeat::QUEUE),
            'queue' => $this->queue(),
            'backup' => BackupStatus::health(),
            'devices' => $this->devices(),
            'alerts' => \App\Domain\Alerts\Alerts::active(),
        ]);
    }

    /** Admin only, like the API's POST /admin/alerts/{id}/ack. */
    public function acknowledge(int $alertId): void
    {
        abort_unless(auth()->check(), 403);

        if ($alert = \App\Models\Alert::find($alertId)) {
            \App\Domain\Alerts\Alerts::acknowledge($alert);
        }
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
