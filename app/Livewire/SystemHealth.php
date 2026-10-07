<?php

namespace App\Livewire;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Health\Heartbeat;
use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;

class SystemHealth extends Component
{
    /** Heartbeats older than this are shown as down (scheduler beats every 60s). */
    private const STALE_SECONDS = 180;

    private const SCAN_LIMIT = 5000;

    public function render()
    {
        return view('livewire.system-health', [
            'broker' => $this->broker(),
            'supervisor' => $this->supervisor(),
            'scheduler' => $this->heartbeat(Heartbeat::SCHEDULER),
            'queueWorker' => $this->heartbeat(Heartbeat::QUEUE),
            'queue' => $this->queue(),
            'pendingJobs' => $this->pendingJobs(),
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
                'at' => Carbon::parse($lastFailure->failed_at),
            ] : null,
        ];
    }

    /**
     * Pending jobs grouped by class, from the `jobs` table (database queue driver only).
     * Scans at most SCAN_LIMIT rows so a huge backlog can't slow the page down.
     *
     * @return array{total: int, scanned: int, groups: list<array{job: string, count: int, waiting: int, running: int, delayed: int, oldest: ?Carbon}>}|null
     */
    private function pendingJobs(): ?array
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            $table = config('queue.connections.database.table', 'jobs');
            $total = DB::table($table)->count();
            $rows = DB::table($table)->orderBy('id')->limit(self::SCAN_LIMIT)->get(['payload', 'available_at', 'reserved_at', 'created_at']);
        } catch (\Throwable) {
            return null;
        }

        $now = now()->timestamp;
        $groups = [];

        foreach ($rows as $row) {
            $name = class_basename(json_decode($row->payload, true)['displayName'] ?? 'Unknown job');
            $group = &$groups[$name];
            $group ??= ['job' => $name, 'count' => 0, 'waiting' => 0, 'running' => 0, 'delayed' => 0, 'oldest' => null];

            $group['count']++;
            if ($row->reserved_at) {
                $group['running']++;
            } elseif ($row->available_at > $now) {
                $group['delayed']++;
            } else {
                $group['waiting']++;
            }
            $created = Carbon::createFromTimestamp($row->created_at);
            if ($group['oldest'] === null || $created->lt($group['oldest'])) {
                $group['oldest'] = $created;
            }
            unset($group);
        }

        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['total' => $total, 'scanned' => $rows->count(), 'groups' => $groups];
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
