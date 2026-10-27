<?php

namespace App\Domain\Health;

use App\Domain\Library\Enrichment;
use App\Jobs\RunStorageTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * What is in the queue and what to do about it, for /settings/health: pending jobs grouped by class
 * (database and redis drivers), failed jobs with retry/forget, a worker restart and a re-queue of the
 * enrichment jobs that are still missing.
 */
class QueueInspector
{
    /** Pending rows read at most, so a huge backlog can't slow the page down. */
    public const SCAN_LIMIT = 5000;

    /** The queues the supervised workers listen on; others found in the driver are added. */
    private const KNOWN_QUEUES = ['default', 'health', RunStorageTask::QUEUE];

    /**
     * Pending jobs grouped by class: waiting (ready), running (reserved by a worker) and delayed (a retry
     * after a rate limit or failure, or a release by a limiter). Null when the driver can't be listed.
     *
     * @return array{total: int, scanned: int, groups: list<array{job: string, count: int, waiting: int, running: int, delayed: int, oldest: ?Carbon}>}|null
     */
    public function pending(): ?array
    {
        try {
            $read = match (config('queue.connections.'.config('queue.default').'.driver')) {
                'database' => $this->databaseRows(),
                'redis' => $this->redisRows(),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }

        if ($read === null) {
            return null;
        }

        [$total, $rows] = $read;
        $groups = [];

        foreach ($rows as [$payload, $state, $created]) {
            $name = class_basename(json_decode($payload, true)['displayName'] ?? 'Unknown job');
            $group = &$groups[$name];
            $group ??= ['job' => $name, 'count' => 0, 'waiting' => 0, 'running' => 0, 'delayed' => 0, 'oldest' => null];

            $group['count']++;
            $group[$state]++;
            if ($created && ($group['oldest'] === null || $created->lt($group['oldest']))) {
                $group['oldest'] = $created;
            }
            unset($group);
        }

        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['total' => $total, 'scanned' => count($rows), 'groups' => $groups];
    }

    /**
     * Per queue: jobs waiting, delayed and running, and how long the head of the waiting list has waited
     * (`oldest_waiting`, null when nothing waits). A runner that is stuck shows as an age that keeps growing.
     * Redis only; null for other drivers.
     *
     * @return list<array{queue: string, waiting: int, delayed: int, running: int, oldest_waiting: ?Carbon}>|null
     */
    public function queues(): ?array
    {
        if (config('queue.connections.'.config('queue.default').'.driver') !== 'redis') {
            return null;
        }

        try {
            $redis = Queue::connection()->getConnection();

            return array_map(function (string $name) use ($redis) {
                $head = $redis->lindex("queues:{$name}", 0);
                $pushed = $head ? (json_decode($head, true)['pushedAt'] ?? null) : null;

                return [
                    'queue' => $name,
                    'waiting' => (int) $redis->llen("queues:{$name}"),
                    'delayed' => (int) $redis->zcard("queues:{$name}:delayed"),
                    'running' => (int) $redis->zcard("queues:{$name}:reserved"),
                    'oldest_waiting' => $pushed ? Carbon::createFromTimestamp((int) $pushed) : null,
                ];
            }, $this->queueNames());
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function queueNames(): array
    {
        return array_values(array_unique([...self::KNOWN_QUEUES, config('analysis.queue'), config('proxies.queue'), config('queue.connections.redis.queue', 'default')]));
    }

    /** @return array{0: int, 1: list<array{0: string, 1: string, 2: ?Carbon}>} */
    private function databaseRows(): array
    {
        $table = config('queue.connections.database.table', 'jobs');
        $total = DB::table($table)->count();
        $now = now()->timestamp;

        $rows = DB::table($table)->orderBy('id')->limit(self::SCAN_LIMIT)->get(['payload', 'available_at', 'reserved_at', 'created_at'])
            ->map(fn ($row) => [
                $row->payload,
                $row->reserved_at ? 'running' : ($row->available_at > $now ? 'delayed' : 'waiting'),
                Carbon::createFromTimestamp($row->created_at),
            ])->all();

        return [$total, $rows];
    }

    /** @return array{0: int, 1: list<array{0: string, 1: string, 2: ?Carbon}>} */
    private function redisRows(): array
    {
        $redis = Queue::connection()->getConnection();
        $names = $this->queueNames();
        $total = 0;
        $rows = [];

        foreach ($names as $name) {
            foreach (['waiting' => "queues:{$name}", 'delayed' => "queues:{$name}:delayed", 'running' => "queues:{$name}:reserved"] as $state => $key) {
                $total += (int) ($state === 'waiting' ? $redis->llen($key) : $redis->zcard($key));
                $room = self::SCAN_LIMIT - count($rows);
                if ($room <= 0) {
                    continue;
                }

                $payloads = $state === 'waiting' ? $redis->lrange($key, 0, $room - 1) : $redis->zrange($key, 0, $room - 1);
                foreach ($payloads as $payload) {
                    $pushed = json_decode($payload, true)['pushedAt'] ?? null;
                    $rows[] = [$payload, $state, $pushed ? Carbon::createFromTimestamp((int) $pushed) : null];
                }
            }
        }

        return [$total, $rows];
    }

    /**
     * The latest failed jobs, newest first.
     *
     * @return list<array{id: string, job: string, queue: string, message: string, at: Carbon}>
     */
    public function failed(int $limit = 25): array
    {
        try {
            return DB::table('failed_jobs')->latest('failed_at')->latest('id')->limit($limit)
                ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(fn ($row) => [
                    'id' => $row->uuid,
                    'job' => class_basename(json_decode($row->payload, true)['displayName'] ?? 'Unknown job'),
                    'queue' => $row->queue,
                    'message' => strtok($row->exception, "\n") ?: '',
                    'at' => Carbon::parse($row->failed_at),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Puts one failed job (or `all`) back on its queue. */
    public function retry(string $id): void
    {
        Artisan::call('queue:retry', ['id' => [$id]]);
    }

    public function forget(string $id): void
    {
        app('queue.failer')->forget($id);
    }

    public function flushFailed(): void
    {
        app('queue.failer')->flush();
    }

    /** Tells every worker to exit after its current job; the supervisor starts them again. */
    public function restartWorkers(): void
    {
        Artisan::call('queue:restart');
    }

    /**
     * Queues the enrichment jobs that tracks, artists and albums still miss (what `library:enrich` does),
     * for up to $limit records of each kind; returns how many records were looked at.
     */
    public function queueMissingEnrichment(int $limit = 200): int
    {
        Enrichment::queueEntities($limit);

        return Enrichment::queueBacklog($limit);
    }
}
