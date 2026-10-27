<?php

namespace App\Domain\Alerts;

use App\Domain\Backup\BackupStatus;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceListeners;
use App\Domain\Device\State;
use App\Domain\Health\ExternalServices;
use App\Domain\Health\Heartbeat;
use App\Domain\Health\QueueInspector;
use App\Models\Device;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns the conditions the health page detects into alerts: raises when one starts, resolves when it ends
 * (alerts:check, every minute). Reads caches and cheap probes only, and a failing check is logged and skipped,
 * so one broken check never stops the others or the scheduler. Thresholds: config/alerts.php.
 */
final class AlertChecks
{
    public function runAll(): void
    {
        foreach (['scheduler', 'queues', 'backup', 'storage', 'devices'] as $check) {
            try {
                $this->{$check}();
            } catch (\Throwable $e) {
                Log::warning("Alert check {$check} failed: {$e->getMessage()}");
            }
        }
    }

    /** scheduler.silent: the scheduler heartbeat is stale or missing. Cheap, so the health page runs it too. */
    public function scheduler(): void
    {
        $last = Heartbeat::last(Heartbeat::SCHEDULER);
        $silent = $last === null || $last->diffInSeconds(now()) >= (int) config('alerts.scheduler_silent_seconds');

        if (!$silent) {
            Alerts::resolve('scheduler.silent');

            return;
        }

        Alerts::raise('scheduler.silent', '', 'critical', 'Scheduler is silent',
            $last ? 'The scheduler last beat '.$last->diffForHumans().'; nothing scheduled runs.' : 'The scheduler has never beaten; nothing scheduled runs.',
            data: ['last_beat' => $last?->toIso8601String()]);
    }

    /**
     * queue.stuck per queue: only `stuck` raises, `busy` (a long job runs) and `unknown` never do.
     *
     * @param  list<array{queue: string, state: string, beat: ?\Illuminate\Support\Carbon}>|null  $queues  default: the inspector's rows (Redis only)
     */
    public function queues(?array $queues = null): void
    {
        $queues ??= (new QueueInspector)->queues() ?? [];

        foreach ($queues as $row) {
            match ($row['state']) {
                'stuck' => Alerts::raise('queue.stuck', $row['queue'], 'critical', "Queue {$row['queue']} is stuck",
                    "The {$row['queue']} queue has not run its heartbeat".(($row['beat'] ?? null) ? ' since '.$row['beat']->diffForHumans() : '').'; its worker is down or hung.',
                    data: ['queue' => $row['queue']]),
                'ok' => Alerts::resolve('queue.stuck', $row['queue']),
                default => null,   // busy and unknown: leave an active alert as it is
            };
        }
    }

    /** backup.stale: no backup, or none newer than backup.stale_hours (BackupStatus). */
    public function backup(): void
    {
        $health = BackupStatus::health();

        if ($health['ok']) {
            Alerts::resolve('backup.stale');

            return;
        }

        Alerts::raise('backup.stale', '', 'warning', $health['last'] ? 'Backup is stale' : 'No backup exists',
            $health['last'] ? 'The last backup is from '.$health['last']->diffForHumans().'.' : 'No backup has been made yet.',
            data: ['last_backup' => $health['last']?->toIso8601String()]);
    }

    /** storage.unreachable per location (the probe of /settings/health), at most every storage_check_minutes. */
    public function storage(): void
    {
        if (!Cache::add('alerts:storage-checked', true, now()->addMinutes((int) config('alerts.storage_check_minutes')))) {
            return;
        }

        $services = app(ExternalServices::class);
        $ids = [];
        foreach (StorageLocation::all() as $location) {
            $ids[] = (string) $location->id;
            $check = $services->probeStorage($location);
            if ($check->status === 'ok') {
                Alerts::resolve('storage.unreachable', (string) $location->id);
            } else {
                Alerts::raise('storage.unreachable', (string) $location->id, 'critical', "Storage {$location->name} is unreachable", $check->message,
                    data: ['location_id' => $location->id]);
            }
        }

        // A deleted location can no longer be unreachable.
        foreach (Alerts::active()->where('kind', 'storage.unreachable')->whereNotIn('key', $ids) as $orphan) {
            Alerts::resolve('storage.unreachable', $orphan->key);
        }
    }

    /**
     * device.offline (state unreachable for device_offline_minutes) and listener.down (no listener heartbeat for
     * listener_down_minutes). Each has to hold continuously for its threshold before it raises, and has to be gone
     * for resolve_after_minutes before it resolves, so a listener restart or a flapping device does not loop.
     */
    public function devices(): void
    {
        $raiseOffline = (int) config('alerts.device_offline_minutes');
        $raiseListener = (int) config('alerts.listener_down_minutes');

        foreach (Device::all() as $device) {
            try {
                $this->device($device, $raiseOffline, $raiseListener);
            } catch (\Throwable $e) {
                Log::warning("Alert check for device {$device->id} failed: {$e->getMessage()}");
            }
        }

        // A deleted device's alerts end with it.
        $ids = Device::pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach (Alerts::active()->whereIn('kind', ['device.offline', 'listener.down'])->whereNotIn('key', $ids) as $orphan) {
            Alerts::resolve($orphan->kind, $orphan->key);
        }
    }

    private function device(Device $device, int $raiseOffline, int $raiseListener): void
    {
        $key = (string) $device->id;
        $name = $device->device_name;

        $offline = DeviceCache::getState($device->id) === State::Unreachable;
        $this->sustained('device.offline', $key, $offline, $raiseOffline,
            fn () => Alerts::raise('device.offline', $key, 'warning', "{$name} is offline", "{$name} has been unreachable for over {$raiseOffline} minutes.", $device->id),
        );

        $listening = DeviceListeners::supports($device) && DeviceListeners::for($device) !== null;
        $down = $listening && !DeviceCache::isListenerRunning($device->id);
        $this->sustained('listener.down', $key, $down, $raiseListener,
            fn () => Alerts::raise('listener.down', $key, 'warning', "{$name}'s listener is down", "No listener has reported for {$name} for over {$raiseListener} minutes, so its state and now playing are not updated.", $device->id),
        );
    }

    /** Raises once `$bad` has held for `$minutes` in a row; resolves once it has been gone for resolve_after_minutes. */
    private function sustained(string $kind, string $key, bool $bad, int $minutes, \Closure $raise): void
    {
        $badSince = "alerts:{$kind}:{$key}:bad-since";
        $goodSince = "alerts:{$kind}:{$key}:good-since";

        if ($bad) {
            Cache::forget($goodSince);
            $since = Cache::add($badSince, now()->timestamp, 86400) ? now()->timestamp : (int) Cache::get($badSince);
            if (now()->timestamp - $since >= $minutes * 60) {
                $raise();
            }

            return;
        }

        Cache::forget($badSince);
        if (!Alerts::isActive($kind, $key)) {
            Cache::forget($goodSince);

            return;
        }

        $since = Cache::add($goodSince, now()->timestamp, 86400) ? now()->timestamp : (int) Cache::get($goodSince);
        if (now()->timestamp - $since >= (int) config('alerts.resolve_after_minutes') * 60) {
            Alerts::resolve($kind, $key);
            Cache::forget($goodSince);
        }
    }
}
