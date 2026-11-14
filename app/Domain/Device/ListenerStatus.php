<?php

namespace App\Domain\Device;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Why a device listener is not running and since when, plus the restart button.
 *
 * `device:listen` (the one process that forks the listeners) writes the lifecycle: started, exited with a code,
 * restarted for a new address, waiting to retry. The listeners themselves report the last error they hit (an
 * unreachable device is retried inside the loop and never ends the process). Everything lives in the cache, like the
 * listener heartbeat; IP changes are kept in a Setting because they are worth keeping across a flush.
 */
class ListenerStatus
{
    public const RUNNING = 'running';

    public const RETRYING = 'retrying';   // the child ended, the manager starts it again after a delay

    public const STOPPED = 'stopped';     // no listener by design (device gone, driver without one)

    private const TTL_DAYS = 14;

    private const IP_HISTORY_KEY = 'device_ip_changes';

    private const IP_HISTORY_MAX = 50;

    /** @return array{state: string, since: int, reason: ?string, pid: ?int, ip: ?string, failures: int, retry_at: ?int, last_error: ?string, last_error_at: ?int}|null */
    public static function get(int $deviceId): ?array
    {
        return Cache::get(self::key($deviceId));
    }

    /** The manager's view: a state change keeps `since` only when the state stays the same. */
    public static function transition(int $deviceId, string $state, ?string $reason = null, array $extra = []): void
    {
        $current = self::get($deviceId) ?? [];

        Cache::put(self::key($deviceId), array_merge([
            'pid' => null,
            'ip' => null,
            'failures' => 0,
            'retry_at' => null,
            'last_error' => $current['last_error'] ?? null,
            'last_error_at' => $current['last_error_at'] ?? null,
        ], $extra, [
            'state' => $state,
            'since' => ($current['state'] ?? null) === $state ? $current['since'] : time(),
            'reason' => $reason,
        ]), now()->addDays(self::TTL_DAYS));
    }

    /** A listener's own report of what went wrong (it keeps retrying; this is what the health page shows). */
    public static function error(int|string $deviceId, \Throwable|string $error): void
    {
        $message = $error instanceof \Throwable ? get_class($error).': '.$error->getMessage() : $error;
        $current = self::get((int) $deviceId) ?? ['state' => self::RUNNING, 'since' => time(), 'reason' => null];

        Cache::put(self::key((int) $deviceId), array_merge($current, [
            'last_error' => mb_substr($message, 0, 500),
            'last_error_at' => time(),
        ]), now()->addDays(self::TTL_DAYS));
    }

    /** A listener that polled fine again forgets its error. */
    public static function recovered(int|string $deviceId): void
    {
        $current = self::get((int) $deviceId);

        if ($current && $current['last_error']) {
            Cache::put(self::key((int) $deviceId), array_merge($current, ['last_error' => null, 'last_error_at' => null]), now()->addDays(self::TTL_DAYS));
        }
    }

    public static function requestRestart(int $deviceId): void
    {
        Cache::put(self::restartKey($deviceId), true, now()->addMinutes(5));
    }

    /** @param  list<int>  $deviceIds  the manager's children and waiting restarts */
    public static function pullRestartRequests(array $deviceIds): array
    {
        return array_values(array_filter($deviceIds, fn (int $id) => Cache::pull(self::restartKey($id)) !== null));
    }

    /** Remembers a changed address (newest first) for the health page; no-op when nothing changed. */
    public static function recordIpChange(int $deviceId, string $name, ?string $from, ?string $to): void
    {
        if ($from === $to) {
            return;
        }

        $history = self::ipChanges();
        array_unshift($history, ['device_id' => $deviceId, 'name' => $name, 'from' => $from, 'to' => $to, 'at' => time()]);

        Setting::set(self::IP_HISTORY_KEY, json_encode(array_slice($history, 0, self::IP_HISTORY_MAX)));
    }

    /** @return list<array{device_id: int, name: string, from: ?string, to: ?string, at: int}> */
    public static function ipChanges(): array
    {
        return json_decode((string) Setting::get(self::IP_HISTORY_KEY, '[]'), true) ?: [];
    }

    private static function key(int $deviceId): string
    {
        return "listener_status_{$deviceId}";
    }

    private static function restartKey(int $deviceId): string
    {
        return "listener_restart_{$deviceId}";
    }
}
