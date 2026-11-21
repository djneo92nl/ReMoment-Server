<?php

namespace App\Domain\Device;

use App\Jobs\ScanForDevices;
use App\Services\NetworkDiscoveryService;
use Illuminate\Support\Facades\Cache;

/**
 * A network scan for devices that records what it finds in the discovery inbox (`DiscoveryInbox`), run from the web page
 * (inline) or from the admin API (queued, `POST /api/admin/discovery/scan`). The state of the last scan is kept in the
 * cache so an app can poll it.
 */
class DeviceScan
{
    private const KEY = 'device_scan:status';

    /** A scan that never reports back (worker killed) stops counting as running after this many seconds. */
    private const STALE = 180;

    public function __construct(private readonly NetworkDiscoveryService $discovery, private readonly DiscoveryInbox $inbox) {}

    /**
     * Whether an entry is a probe target: an IPv4 address or an http(s) URL. Anything else is dropped.
     *
     * @return list<string>
     */
    public static function parseHosts(string|array $hosts): array
    {
        $list = is_array($hosts) ? $hosts : preg_split('/[\s,;]+/', $hosts, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            array_map('trim', $list),
            fn ($h) => filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || preg_match('#^https?://[^/\s]+#i', $h)
        ));
    }

    /**
     * Scans now and records the finds.
     *
     * @param  list<string>  $hosts  extra addresses probed directly, for when multicast does not reach the server
     * @return array{found: int, new: int, known: int, summary: string, diagnostics: list<string>}
     */
    public function run(array $hosts = []): array
    {
        $found = $this->discovery->discoverAll(hosts: $hosts);
        $counts = $this->inbox->record(array_values($found));

        return [
            'found' => count($found),
            'new' => $counts['new'],
            'known' => $counts['known'],
            'summary' => $found === [] ? 'No devices responded.' : "{$counts['new']} new, {$counts['known']} already added.",
            'diagnostics' => $this->discovery->diagnostics,
        ];
    }

    /**
     * Queues a scan unless one is running already. Returns the state (`running`).
     *
     * @param  list<string>  $hosts
     * @return array<string, mixed>
     */
    public static function start(array $hosts = []): array
    {
        $status = self::status();
        if ($status['state'] === 'running') {
            return $status;
        }

        $state = ['state' => 'running', 'started_at' => now()->toIso8601String(), 'finished_at' => null, 'result' => null, 'error' => null];
        Cache::put(self::KEY, $state, 3600);
        ScanForDevices::dispatch($hosts);

        return self::status();
    }

    /** Called by the job. */
    public static function finish(?array $result, ?string $error = null): void
    {
        $state = Cache::get(self::KEY) ?? ['started_at' => now()->toIso8601String()];

        Cache::put(self::KEY, [
            'state' => $error === null ? 'done' : 'failed',
            'started_at' => $state['started_at'],
            'finished_at' => now()->toIso8601String(),
            'result' => $result,
            'error' => $error,
        ], 3600);
    }

    /** @return array{state: string, started_at: ?string, finished_at: ?string, result: ?array, error: ?string} state is idle | running | done | failed */
    public static function status(): array
    {
        $state = Cache::get(self::KEY);

        if (!is_array($state)) {
            return ['state' => 'idle', 'started_at' => null, 'finished_at' => null, 'result' => null, 'error' => null];
        }

        if ($state['state'] === 'running' && now()->diffInSeconds(\Carbon\Carbon::parse($state['started_at'])) > self::STALE) {
            return ['state' => 'failed', 'finished_at' => null, 'result' => null, 'error' => 'The scan did not report back (is the queue worker running?).'] + $state;
        }

        return $state;
    }
}
