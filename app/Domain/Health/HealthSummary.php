<?php

namespace App\Domain\Health;

use App\Domain\Alerts\Alerts;
use App\Domain\Backup\BackupStatus;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\ListenerStatus;
use App\Domain\Radio\Relay\RelayStatus;
use App\Domain\System\InstalledVersion;
use App\Domain\System\UpgradeHistory;
use App\Models\Device;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * What `/settings/health` shows about the running server, as data: the web page (Livewire\SystemHealth) and
 * `GET /api/admin/health` read it from here, so the two never disagree (workstream 8b, docs/api/admin-health.md).
 *
 * Every read is cheap and never waits on a device: caches, heartbeats, one TCP connect to the broker (1 s).
 */
class HealthSummary
{
    /** Heartbeats older than this are shown as down (the scheduler beats every 60 s). */
    public const STALE_SECONDS = 180;

    /**
     * The page's data. Carbon dates and Device models stay as they are (the view formats them); `toApi()` is the JSON form.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        // A dead scheduler can't run alerts:check; whoever looks at the health can notice it (a cache read).
        try {
            app(\App\Domain\Alerts\AlertChecks::class)->scheduler();
        } catch (\Throwable) {
        }

        return [
            'broker' => $this->broker(),
            'supervisor' => $this->supervisor(),
            'scheduler' => $this->heartbeat(Heartbeat::SCHEDULER),
            'queueWorker' => $this->heartbeat(Heartbeat::QUEUE),
            'queue' => $this->queue(),
            'backup' => BackupStatus::health(),
            'installedVersion' => InstalledVersion::recorded() ?? InstalledVersion::current(),
            'upgrade' => UpgradeHistory::latest(),
            'relay' => RelayStatus::report(),
            'devices' => $this->devices(),
            'alerts' => Alerts::active(),
            'ipChanges' => array_slice(ListenerStatus::ipChanges(), 0, 5),
        ];
    }

    /**
     * `GET /api/admin/health`: the same report as JSON. `ok` is the one flag an app can show in a badge: broker up,
     * scheduler and queue worker beating, the last backup fresh, and no active alert above `info`.
     *
     * @return array<string, mixed>
     */
    public function toApi(?array $report = null): array
    {
        $report ??= $this->report();
        $iso = fn (?CarbonInterface $at) => $at?->toIso8601String();
        $alerts = $report['alerts'];

        $critical = $alerts->whereIn('severity', ['warning', 'critical'])->count();
        $ok = $report['broker']['ok'] && $report['scheduler']['ok'] && $report['queueWorker']['ok'] && $report['backup']['ok'] && $critical === 0;

        return [
            'ok' => $ok,
            'version' => $report['installedVersion'],
            'broker' => $report['broker'],
            'supervisor' => ['running' => $report['supervisor']],
            'scheduler' => ['ok' => $report['scheduler']['ok'], 'last_beat_at' => $iso($report['scheduler']['last'])],
            'queue_worker' => ['ok' => $report['queueWorker']['ok'], 'last_beat_at' => $iso($report['queueWorker']['last'])],
            'queue' => $report['queue'],
            'backup' => ['ok' => $report['backup']['ok'], 'last_at' => $iso($report['backup']['last'])],
            'upgrade' => $report['upgrade'],
            'radio_relay' => $report['relay'],
            'alerts' => [
                'active' => $alerts->count(),
                'warning_or_critical' => $critical,
                'data' => $alerts->map->toPayload()->values()->all(),
            ],
            'devices' => array_map(fn (array $row) => [
                'id' => $row['device']->id,
                'name' => $row['device']->device_name,
                'state' => $row['state']?->value ?? $row['state'],
                'last_seen' => $row['last_seen'] ? (is_numeric($row['last_seen']) ? date('c', (int) $row['last_seen']) : (string) $row['last_seen']) : null,
                'volume' => $row['volume'] === false ? null : $row['volume'],
                'listener' => $this->listener($row),
            ], $report['devices']),
            'ip_changes' => array_map(fn (array $c) => [
                'device_id' => $c['device_id'], 'name' => $c['name'], 'from' => $c['from'], 'to' => $c['to'], 'at' => date('c', (int) $c['at']),
            ], $report['ipChanges']),
        ];
    }

    /** @return array{running: bool, state: ?string, since: ?string, failures: int, last_error: ?string, retry_at: ?string} */
    private function listener(array $row): array
    {
        $status = $row['listener'] ?? null;

        return [
            'running' => (bool) $row['listener_running'],
            'state' => $status['state'] ?? null,
            'since' => isset($status['since']) ? date('c', (int) $status['since']) : null,
            'failures' => (int) ($status['failures'] ?? 0),
            'last_error' => $status['last_error'] ?? null,
            'retry_at' => isset($status['retry_at']) ? date('c', (int) $status['retry_at']) : null,
        ];
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
                'listener' => ListenerStatus::get($device->id),
                'state' => DeviceCache::getState($device->id),
                'last_seen' => $lastSeen ?: null,
                'volume' => Volume::getVolume($device->id),
            ];
        })->all();
    }
}
