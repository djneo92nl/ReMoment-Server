<?php

namespace App\Livewire;

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
        return view('livewire.system-health', [
            'broker' => $this->broker(),
            'scheduler' => $this->heartbeat(Heartbeat::SCHEDULER),
            'queueWorker' => $this->heartbeat(Heartbeat::QUEUE),
            'queue' => $this->queue(),
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
            $lastFailure = DB::table('failed_jobs')->latest('failed_at')->first(['payload', 'exception', 'failed_at']);
        } catch (\Throwable) {
            $failedCount = null;
            $lastFailure = null;
        }

        return [
            'pending' => $pending,
            'failed' => $failedCount,
            'last_failure' => $lastFailure ? [
                'job' => class_basename(json_decode($lastFailure->payload, true)['displayName'] ?? 'Unknown job'),
                'message' => strtok($lastFailure->exception, "\n"),
                'at' => \Illuminate\Support\Carbon::parse($lastFailure->failed_at),
            ] : null,
        ];
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
