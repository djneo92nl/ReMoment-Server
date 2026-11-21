<?php

namespace App\Domain\Health;

use App\Domain\Health\Store\QueueStores;
use App\Domain\Library\Enrichment;
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
    /**
     * Pending jobs grouped by class: waiting (ready), running (reserved by a worker) and delayed (a retry
     * after a rate limit or failure, or a release by a limiter). Null when the driver can't be listed.
     *
     * @return array{total: int, scanned: int, groups: list<array{job: string, class: string, queue: string, count: int, waiting: int, running: int, delayed: int, oldest: ?Carbon}>}|null
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

        foreach ($rows as [$payload, $state, $created, $queue]) {
            $class = json_decode($payload, true)['displayName'] ?? 'Unknown job';
            $name = class_basename($class);
            $group = &$groups[$queue.'|'.$name];
            $group ??= ['job' => $name, 'class' => $class, 'queue' => $queue, 'count' => 0, 'waiting' => 0, 'running' => 0, 'delayed' => 0, 'oldest' => null];

            $group['count']++;
            $group[$state]++;
            if ($created && ($group['oldest'] === null || $created->lt($group['oldest']))) {
                $group['oldest'] = $created;
            }
            unset($group);
        }

        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['total' => $total, 'scanned' => count($rows), 'groups' => $groups];
    }

    /**
     * Per queue: jobs waiting, delayed and running, the age of the oldest waiting job, whether the queue is paused,
     * how many worker processes listen on it (null when the process table can't be read), failed jobs in the last 24 hours,
     * when a job last finished and whether the queue is lagging (see `lagging()`). A runner that is stuck shows as an age
     * that keeps growing. Redis and database drivers; null for the others.
     *
     * @return list<array{queue: string, beat: ?Carbon, state: string, waiting: int, delayed: int, running: int, oldest_waiting: ?Carbon, oldest_age: ?int, paused: bool, workers: ?int, worker: string, failed_24h: int, last_progress: ?Carbon, lag_after: int, lagging: bool}>|null
     */
    public function queues(): ?array
    {
        $store = QueueStores::current();
        if ($store === null) {
            return null;
        }

        try {
            $failed = $this->failedLast24h();
            $workers = app(QueueWorkers::class)->counts();

            return array_map(function (string $name) use ($store, $failed, $workers) {
                $stats = $store->stats($name);
                $beat = in_array($name, Queues::heartbeat(), true) ? Heartbeat::last(Queues::heartbeatKey($name)) : null;
                $oldest = $stats['oldest_waiting'];
                $count = $workers === null ? null : ($workers[$name] ?? 0);

                return [
                    'queue' => $name,
                    'beat' => $beat,
                    'state' => $this->runnerState($name, $beat, $stats['running']),
                    'waiting' => $stats['waiting'],
                    'delayed' => $stats['delayed'],
                    'running' => $stats['running'],
                    'oldest_waiting' => $oldest,
                    'oldest_age' => $oldest ? (int) $oldest->diffInSeconds(now()) : null,
                    'paused' => QueuePause::isPaused($name),
                    'workers' => $count,
                    'worker' => $count === null ? 'unknown' : ($count > 0 ? 'present' : 'absent'),
                    'failed_24h' => $failed[$name] ?? 0,
                    'last_progress' => QueueProgress::last($name),
                    'lag_after' => Queues::lagAfter($name),
                    'lagging' => $this->lagging($name, $stats),
                ];
            }, $this->queueNames());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A queue is lagging when its oldest waiting job has waited longer than the queue's threshold (`config('queues.lag_after')`)
     * and nothing moves: no job finished within the threshold and none runs within its allowed runtime. A paused queue
     * never lags. A backlog that drains is waiting, not lagging.
     *
     * @param  array{waiting: int, running: int, oldest_waiting: ?Carbon, longest_running: ?int}  $stats
     */
    public function lagging(string $queue, array $stats): bool
    {
        $limit = Queues::lagAfter($queue);

        if (QueuePause::isPaused($queue) || $stats['waiting'] === 0 || $stats['oldest_waiting'] === null || $stats['oldest_waiting']->diffInSeconds(now()) <= $limit) {
            return false;
        }

        $finished = QueueProgress::last($queue);
        if ($finished !== null && $finished->diffInSeconds(now()) <= $limit) {
            return false;
        }

        return !($stats['running'] > 0 && ($stats['longest_running'] ?? PHP_INT_MAX) < Queues::maxRuntime($queue));
    }

    /** @return array<string, int> failed jobs of the last 24 hours per queue */
    private function failedLast24h(): array
    {
        try {
            return DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->selectRaw('queue, count(*) as total')->groupBy('queue')
                ->pluck('total', 'queue')->map(fn ($n) => (int) $n)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * `paused` (an admin paused the queue), `ok` (a heartbeat ran in the last 3 minutes), `busy` (none, but a job runs that is younger than the queue's
     * longest timeout), `stuck` (neither: the runner is down or hung), `unknown` (no heartbeat is dispatched for this
     * queue, or the scheduler is down).
     */
    public function runnerState(string $queue, ?Carbon $beat, int $running): string
    {
        if (QueuePause::isPaused($queue)) {
            return 'paused';   // nothing is taken on purpose, so the heartbeat waits too
        }

        if (!in_array($queue, Queues::heartbeat(), true) || !self::fresh(Heartbeat::last(Heartbeat::SCHEDULER))) {
            return 'unknown';
        }

        if (self::fresh($beat)) {
            return 'ok';
        }

        $age = $running > 0 ? Queues::longestRunningSeconds($queue) : null;

        return $age !== null && $age < Queues::maxRuntime($queue) ? 'busy' : 'stuck';
    }

    private static function fresh(?Carbon $beat): bool
    {
        return $beat !== null && $beat->diffInSeconds(now()) < 180;
    }

    /** @return list<string> */
    private function queueNames(): array
    {
        return Queues::all();
    }

    /** @return array{0: int, 1: list<array{0: string, 1: string, 2: ?Carbon, 3: string}>} */
    private function databaseRows(): array
    {
        $table = config('queue.connections.database.table', 'jobs');
        $total = DB::table($table)->count();
        $now = now()->timestamp;

        $rows = DB::table($table)->orderBy('id')->limit(self::SCAN_LIMIT)->get(['payload', 'queue', 'available_at', 'reserved_at', 'created_at'])
            ->map(fn ($row) => [
                $row->payload,
                $row->reserved_at ? 'running' : ($row->available_at > $now ? 'delayed' : 'waiting'),
                Carbon::createFromTimestamp($row->created_at),
                $row->queue,
            ])->all();

        return [$total, $rows];
    }

    /** @return array{0: int, 1: list<array{0: string, 1: string, 2: ?Carbon, 3: string}>} */
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
                    $rows[] = [$payload, $state, $pushed ? Carbon::createFromTimestamp((int) $pushed) : null, $name];
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
