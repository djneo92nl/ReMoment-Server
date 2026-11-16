<?php

namespace App\Domain\LineIn;

use App\Domain\Node\NodeJobs;
use App\Domain\Radio\Relay\IcecastStatus;
use App\Domain\Storage\StreamUrl;
use App\Models\Client;
use App\Models\NodeJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Line-in as a stream source (workstream 18.2, docs/architecture/line-in.md): one source per node that has the
 * `line-in` role switched on. The node's ffmpeg pushes MP3 to the Icecast mount `linein-{node id}.mp3`; any speaker
 * with `stream_url` plays that URL. The cache holds demand (when a device last asked, since when nobody listens), the
 * node jobs `line_in.start` / `line_in.stop` do the work on the node.
 */
final class LineIns
{
    private const WANTED_KEY = 'line_in:wanted';

    /** @return Collection<int, Client> approved nodes with the line-in role switched on */
    public static function nodes(): Collection
    {
        return Client::query()->where('kind', 'node')->where('status', 'approved')->orderBy('id')->get()
            ->filter(fn (Client $c) => in_array('line-in', $c->activeRoles(), true))->values();
    }

    public static function find(int $nodeId): ?Client
    {
        return self::nodes()->firstWhere('id', $nodeId);
    }

    public static function mount(Client $node): string
    {
        return "linein-{$node->id}.mp3";
    }

    /** The address a speaker pulls: the host of the other stream addresses (`STREAM_BASE_URL`) on the Icecast port. */
    public static function url(Client $node): string
    {
        $host = parse_url(StreamUrl::base(), PHP_URL_HOST) ?: '127.0.0.1';

        return 'http://'.(str_contains($host, ':') ? "[$host]" : $host).':'.config('radio_relay.icecast.port').'/'.self::mount($node);
    }

    public static function title(Client $node): string
    {
        return 'Line-in';
    }

    public static function label(Client $node): string
    {
        return 'Line-in on '.($node->name ?: "node #{$node->id}");
    }

    public static function online(Client $node): bool
    {
        return $node->last_seen_at !== null && $node->last_seen_at->gt(now()->subSeconds((int) config('alerts.node_offline_seconds', 90)));
    }

    /** @return array<string, mixed> */
    public static function describe(Client $node, ?IcecastStatus $icecast = null): array
    {
        $mounts = ($icecast ?? new IcecastStatus)->mounts();
        $mount = self::mount($node);
        $role = $node->node_status['roles']['line-in'] ?? null;
        $inputs = $node->capabilities['line-in']['inputs'] ?? [];

        return [
            'id' => $node->id,
            'name' => self::label($node),
            'node_name' => $node->name,
            'title' => self::title($node),
            'mount' => $mount,
            'url' => self::url($node),
            'node_online' => self::online($node),
            'node_state' => is_array($role) ? ($role['state'] ?? 'unknown') : 'unknown',
            'detail' => is_array($role) ? ($role['detail'] ?? null) : null,
            'inputs' => array_values(array_map(fn ($i) => ['id' => (string) ($i['id'] ?? ''), 'label' => (string) ($i['label'] ?? '')], is_array($inputs) ? $inputs : [])),
            'live' => isset($mounts[$mount]),
            'listeners' => $mounts[$mount]['listeners'] ?? 0,
            'device_ids' => array_values(self::wanted()[$node->id]['devices'] ?? []),
        ];
    }

    /** @return array<int, array{at: int, devices: list<int>, idle_since: ?int}> */
    private static function wanted(): array
    {
        return Cache::get(self::WANTED_KEY, []);
    }

    public static function anyWanted(): bool
    {
        return self::wanted() !== [];
    }

    /** Remembers that a device was told to play this line-in (demand for the idle stop). */
    public static function addTarget(Client $node, int $deviceId): void
    {
        $wanted = self::wanted();
        $entry = $wanted[$node->id] ?? ['at' => 0, 'devices' => [], 'idle_since' => null];
        $entry['at'] = now()->timestamp;
        $entry['idle_since'] = null;
        $entry['devices'] = array_values(array_unique([...$entry['devices'], $deviceId]));
        $wanted[$node->id] = $entry;
        Cache::put(self::WANTED_KEY, $wanted, now()->addDay());
    }

    private static function forget(int $nodeId): void
    {
        $wanted = self::wanted();
        unset($wanted[$nodeId]);
        Cache::put(self::WANTED_KEY, $wanted, now()->addDay());
    }

    /** Is there a start or stop job of this type for the node that nobody has finished yet? */
    private static function pending(Client $node, string $type): bool
    {
        NodeJobs::sweep($node);

        return NodeJob::where('client_id', $node->id)->where('type', $type)->whereIn('state', ['queued', 'accepted', 'running'])->exists();
    }

    /**
     * Asks the node to capture and waits until the mount has a source, or the time is up. One encode per line-in: an
     * open start job is reused, a mount that is live needs no job. False when it never came.
     */
    public static function ensureLive(Client $node, ?int $seconds = null, ?IcecastStatus $icecast = null): bool
    {
        $icecast ??= new IcecastStatus;
        $mount = self::mount($node);

        if (!$icecast->isLive($mount) && !self::pending($node, 'line_in.start')) {
            NodeJobs::create($node, 'line_in.start', ['mount' => $mount]);
        }

        $deadline = microtime(true) + ($seconds ?? (int) config('line_in.live_wait_seconds'));

        do {
            if ($icecast->isLive($mount)) {
                return true;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public static function stop(Client $node): void
    {
        if (!self::pending($node, 'line_in.stop')) {
            NodeJobs::create($node, 'line_in.stop');
        }
        self::forget($node->id);
    }

    /**
     * Stops line-ins nobody listens to: a live mount with no listener for `line_in.idle_seconds`, or one that has
     * not come up for that long after it was asked for. Scheduled every minute while something is wanted.
     *
     * @return list<int> node ids stopped
     */
    public static function sweep(?IcecastStatus $icecast = null): array
    {
        $mounts = ($icecast ?? new IcecastStatus)->mounts();
        $idle = (int) config('line_in.idle_seconds');
        $stopped = [];

        foreach (self::wanted() as $nodeId => $entry) {
            $node = Client::find($nodeId);
            if ($node === null || !in_array('line-in', $node->activeRoles(), true)) {
                self::forget($nodeId);

                continue;
            }

            $mount = self::mount($node);

            if (isset($mounts[$mount]) && $mounts[$mount]['listeners'] > 0) {
                self::mark($nodeId, null);

                continue;
            }

            $since = isset($mounts[$mount]) ? ($entry['idle_since'] ?? now()->timestamp) : $entry['at'];

            if (isset($mounts[$mount]) && $entry['idle_since'] === null) {
                self::mark($nodeId, $since);
            }

            if (now()->timestamp - $since >= $idle) {
                self::stop($node);
                $stopped[] = $nodeId;
            }
        }

        return $stopped;
    }

    private static function mark(int $nodeId, ?int $idleSince): void
    {
        $wanted = self::wanted();
        if (isset($wanted[$nodeId])) {
            $wanted[$nodeId]['idle_since'] = $idleSince;
            Cache::put(self::WANTED_KEY, $wanted, now()->addDay());
        }
    }
}
