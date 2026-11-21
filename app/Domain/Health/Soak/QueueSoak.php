<?php

namespace App\Domain\Health\Soak;

use App\Domain\Health\QueueInspector;
use App\Domain\Health\Queues;
use App\Jobs\Soak\SoakNoop;
use Illuminate\Support\Facades\Cache;

/**
 * The queue soak (workstream 1b, docs/architecture/queue-management.md): `enqueue` puts a mixed load of no-op jobs on every
 * queue the profile runs, `sample` records one line of health and worker memory, `report` turns the lines into report.md.
 * scripts/queue-soak/run.sh drives it for 24 hours; this class holds the parts a test can check.
 */
final class QueueSoak
{
    /** kind => share of the load: mostly instant jobs, some waiting, some holding memory, a few failing. */
    private const MIX = ['noop' => 70, 'sleep' => 15, 'memory' => 10, 'fail' => 5];

    /** Queues that get load: every queue of the profile except `health` (the heartbeat's) and `history` (play history's single writer). */
    public static function queues(): array
    {
        return array_values(array_diff(Queues::all(), [Queues::HEALTH, 'history']));
    }

    /** @return array<string, int> queue => jobs queued */
    public static function enqueue(int $perQueue, ?int $seed = null): array
    {
        $seed === null || mt_srand($seed);
        $queued = [];

        foreach (self::queues() as $queue) {
            for ($i = 0; $i < $perQueue; $i++) {
                [$kind, $param] = self::pick();
                SoakNoop::dispatch($kind, $param)->onQueue($queue);
            }
            $queued[$queue] = $perQueue;
        }
        Cache::increment('queue-soak:queued', array_sum($queued));

        return $queued;
    }

    /** @return array{0: string, 1: int} */
    private static function pick(): array
    {
        $roll = mt_rand(1, 100);
        foreach (self::MIX as $kind => $share) {
            if (($roll -= $share) <= 0) {
                return [$kind, match ($kind) {
                    'sleep' => mt_rand(50, 1500), 'memory' => mt_rand(4, 24), default => 0
                }];
            }
        }

        return ['noop', 0];
    }

    /**
     * One sample: the time, per queue the health row's numbers, and the resident memory of every `queue:work` process.
     *
     * @param  ?list<array{pid: int, queues: string, rss_kb: int}>  $workers  default: read from /proc
     * @return array<string, mixed>
     */
    public static function sample(?array $workers = null): array
    {
        $queues = [];
        foreach ((new QueueInspector)->queues() ?? [] as $row) {
            $queues[$row['queue']] = [
                'waiting' => $row['waiting'], 'delayed' => $row['delayed'], 'running' => $row['running'],
                'oldest_age' => $row['oldest_age'], 'failed_24h' => $row['failed_24h'], 'state' => $row['state'], 'lagging' => $row['lagging'],
            ];
        }

        return [
            'at' => now()->toIso8601String(),
            'queued' => (int) Cache::get('queue-soak:queued', 0),
            'done' => (int) Cache::get('queue-soak:done', 0),
            'queues' => $queues,
            'workers' => $workers ?? self::workers(),
        ];
    }

    /** @return list<array{pid: int, queues: string, rss_kb: int}> */
    public static function workers(): array
    {
        $found = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmd = str_replace("\0", ' ', (string) @file_get_contents($file));
            if (!str_contains($cmd, 'artisan queue:work') || !preg_match('/--queue=(\S+)/', $cmd, $m)) {
                continue;
            }
            $status = (string) @file_get_contents(dirname($file).'/status');
            $found[] = ['pid' => (int) basename(dirname($file)), 'queues' => $m[1], 'rss_kb' => preg_match('/VmRSS:\s+(\d+)/', $status, $r) ? (int) $r[1] : 0];
        }

        return $found;
    }

    /**
     * report.md from the sample lines (JSON, one per line, oldest first).
     *
     * @param  list<array<string, mixed>>  $samples
     */
    public static function report(array $samples): string
    {
        if ($samples === []) {
            return "# Queue soak\n\nNo samples were recorded.\n";
        }

        $first = $samples[0];
        $last = $samples[count($samples) - 1];
        $hours = max(0.0, (strtotime($last['at']) - strtotime($first['at'])) / 3600);
        $out = ['# Queue soak', '', sprintf('%d samples over %.1f hours (%s to %s).', count($samples), $hours, $first['at'], $last['at']), ''];

        $out[] = sprintf('Jobs queued: %d, done: %d, still not done: %d.', $last['queued'], $last['done'], max(0, $last['queued'] - $last['done']));
        $out[] = '';
        $out[] = '| queue | peak waiting | oldest waiting (max, s) | failed in 24 h (last) | samples lagging or stuck |';
        $out[] = '|---|---|---|---|---|';
        $problems = [];
        foreach (array_keys($last['queues'] ?? []) as $queue) {
            $rows = array_filter(array_column($samples, 'queues'), fn ($q) => isset($q[$queue]));
            $rows = array_column($rows, $queue);
            $bad = count(array_filter($rows, fn ($r) => $r['lagging'] || $r['state'] === 'stuck'));
            $out[] = sprintf('| %s | %d | %d | %d | %d |', $queue, max(array_column($rows, 'waiting')), max(array_map(fn ($r) => (int) $r['oldest_age'], $rows)), $last['queues'][$queue]['failed_24h'], $bad);
            $bad > 0 && $problems[] = "queue {$queue} was lagging or stuck in {$bad} samples";
        }

        $out[] = '';
        $out[] = '## Worker memory (resident, MB)';
        $out[] = '';
        $out[] = '| queues | processes seen | first | peak | last | restarts (new pids) |';
        $out[] = '|---|---|---|---|---|---|';
        foreach (self::memoryByWorker($samples) as $queues => $w) {
            $out[] = sprintf('| %s | %d | %.0f | %.0f | %.0f | %d |', $queues, count($w['pids']), $w['first'] / 1024, $w['peak'] / 1024, $w['last'] / 1024, max(0, count($w['pids']) - 1));
            $w['peak'] / 1024 > 160 && $problems[] = "workers of {$queues} peaked at ".round($w['peak'] / 1024).' MB (--memory=128 should restart them)';
        }

        $out[] = '';
        $out[] = '## Verdict';
        $out[] = '';
        $out[] = $problems === [] ? 'No queue lagged or was stuck and no worker outgrew its limit. Compare failed jobs with the `fail` share of the load (5 %): any other failure needs an explanation.' : '- '.implode("\n- ", $problems);

        return implode("\n", $out)."\n";
    }

    /** @return array<string, array{pids: array<int, true>, first: int, peak: int, last: int}> */
    private static function memoryByWorker(array $samples): array
    {
        $by = [];
        foreach ($samples as $sample) {
            foreach ($sample['workers'] ?? [] as $w) {
                $entry = &$by[$w['queues']];
                $entry ??= ['pids' => [], 'first' => $w['rss_kb'], 'peak' => 0, 'last' => 0];
                $entry['pids'][$w['pid']] = true;
                $entry['peak'] = max($entry['peak'], $w['rss_kb']);
                $entry['last'] = $w['rss_kb'];
                unset($entry);
            }
        }

        return $by;
    }
}
