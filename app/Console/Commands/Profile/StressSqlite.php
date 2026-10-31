<?php

namespace App\Console\Commands\Profile;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Simulates the write load of the Control profile on a temporary SQLite file: fake device listeners, the single play
 * history writer, queue workers, web requests and the scheduler, each its own PHP process, for a number of minutes. It
 * counts `database is locked` errors, writes per second and the latency of a write, and prints a verdict. This is the
 * stress test the roadmap (workstream 1a) gates Control on: run it for 24 hours on a real Raspberry Pi.
 *
 * Never touches the configured database: it makes (and removes) its own file.
 */
class StressSqlite extends Command
{
    protected $signature = 'profile:stress-sqlite
        {--minutes=1 : How long to run (the roadmap gate is 1440)}
        {--listeners=6 : Fake device listeners, one process each}
        {--workers=2 : Queue workers writing settings and jobs-like rows}
        {--web=2 : Web request processes (reads and now and then a write)}
        {--intensity=1 : Multiplies every write rate (2 = twice as often)}
        {--file= : SQLite file to use (must not exist; default: a temp file, removed afterwards)}
        {--keep : Keep the file}
        {--max-p95=250 : Verdict fails when the p95 write latency (ms) is above this}
        {--json : Print the result as JSON}
        {--worker= : (internal) run one role of the load}
        {--id=0 : (internal)}
        {--until=0 : (internal) unix time (float) to stop}
        {--rate=1 : (internal) seconds between writes}';

    protected $description = 'Stress a temporary SQLite file with the Control profile write load and count lock errors (the 24-hour gate of the roadmap)';

    public function handle(): int
    {
        if ($this->option('worker')) {
            return $this->runWorker((string) $this->option('worker'), (int) $this->option('id'), (float) $this->option('until'), (float) $this->option('rate'));
        }

        return $this->orchestrate();
    }

    // --- Orchestrator -------------------------------------------------------------------

    private function orchestrate(): int
    {
        $file = (string) ($this->option('file') ?: sys_get_temp_dir().'/remoment-stress-'.bin2hex(random_bytes(4)).'.sqlite');
        $configured = (string) config('database.connections.sqlite.database');

        if (file_exists($file) || realpath(dirname($file)).'/'.basename($file) === $configured) {
            $this->error("Refusing to use {$file}: it exists or is the configured database. This command only runs on a file it creates.");

            return self::FAILURE;
        }

        $minutes = max(0.05, (float) $this->option('minutes'));
        $intensity = max(0.1, (float) $this->option('intensity'));
        $listeners = max(1, (int) $this->option('listeners'));
        $env = [
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file, 'PROFILE' => 'control',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null',
            'DB_URL' => '', 'DB_JOURNAL_MODE' => (string) env('DB_JOURNAL_MODE', 'WAL'), 'DB_BUSY_TIMEOUT' => (string) env('DB_BUSY_TIMEOUT', 10000),
        ];

        $this->info("Stress on {$file} for {$minutes} min: {$listeners} listeners, ".(int) $this->option('workers').' workers, '.(int) $this->option('web').' web, 1 history writer, 1 scheduler.');

        touch($file);
        $migrate = new Process([PHP_BINARY, base_path('artisan'), 'migrate', '--force'], base_path(), $env, null, 300);
        $migrate->run();
        if (!$migrate->isSuccessful()) {
            $this->error('migrate failed: '.$migrate->getErrorOutput().$migrate->getOutput());
            $this->cleanup($file);

            return self::FAILURE;
        }

        $this->seedDevices($env, $file, $listeners);

        $start = microtime(true);
        $until = $start + $minutes * 60;
        $procs = [];
        $spawn = function (string $role, int $id, float $rate) use (&$procs, $env, $until) {
            $p = new Process([PHP_BINARY, base_path('artisan'), 'profile:stress-sqlite', "--worker={$role}", "--id={$id}", '--until='.$until, "--rate={$rate}"], base_path(), $env, null, null);
            $p->start();
            $procs[] = [$role, $p];
        };
        for ($i = 1; $i <= $listeners; $i++) {
            $spawn('listener', $i, 1 / $intensity);
        }
        $spawn('history', 0, 2 / $intensity);
        for ($i = 1; $i <= (int) $this->option('workers'); $i++) {
            $spawn('worker', $i, 1 / $intensity);
        }
        for ($i = 1; $i <= (int) $this->option('web'); $i++) {
            $spawn('web', $i, 0.5 / $intensity);
        }
        $spawn('scheduler', 0, 5 / $intensity);

        while (array_filter($procs, fn ($p) => $p[1]->isRunning())) {
            usleep(500000);
        }

        $latencies = [];
        $stats = ['writes' => 0, 'reads' => 0, 'locked' => 0, 'errors' => 0, 'first_error' => null, 'crashed' => 0];
        $byRole = [];
        foreach ($procs as [$role, $p]) {
            $lines = array_filter(explode("\n", trim($p->getOutput())));
            $result = json_decode((string) end($lines), true);
            if (!is_array($result)) {
                $stats['crashed']++;
                $stats['first_error'] ??= trim($p->getErrorOutput()) ?: 'worker produced no result';

                continue;
            }
            $stats['writes'] += $result['writes'];
            $stats['reads'] += $result['reads'];
            $stats['locked'] += $result['locked'];
            $stats['errors'] += $result['errors'];
            $stats['first_error'] ??= $result['first_error'];
            $latencies = array_merge($latencies, $result['latencies']);
            $byRole[$role] = ($byRole[$role] ?? 0) + $result['writes'];
        }

        $elapsed = microtime(true) - $start;
        sort($latencies);
        $pct = fn (float $q) => $latencies === [] ? 0.0 : round($latencies[(int) min(count($latencies) - 1, floor($q * count($latencies)))], 2);

        $db = new \PDO("sqlite:{$file}");
        $integrity = (string) $db->query('pragma integrity_check')->fetchColumn();
        $journal = (string) $db->query('pragma journal_mode')->fetchColumn();
        $db = null;

        $maxP95 = (float) $this->option('max-p95');
        $result = [
            'file' => $file,
            'minutes' => round($elapsed / 60, 2),
            'processes' => count($procs),
            'journal_mode' => $journal,
            'writes' => $stats['writes'],
            'writes_per_second' => round($stats['writes'] / max(1, $elapsed), 1),
            'reads' => $stats['reads'],
            'writes_by_role' => $byRole,
            'database_is_locked' => $stats['locked'],
            'other_errors' => $stats['errors'],
            'crashed_workers' => $stats['crashed'],
            'first_error' => $stats['first_error'],
            'write_latency_ms' => ['p50' => $pct(0.5), 'p95' => $pct(0.95), 'p99' => $pct(0.99), 'max' => $latencies === [] ? 0 : round(end($latencies), 2)],
            'integrity_check' => $integrity,
        ];
        $result['verdict'] = ($stats['locked'] === 0 && $stats['errors'] === 0 && $stats['crashed'] === 0 && $integrity === 'ok' && $result['write_latency_ms']['p95'] <= $maxP95) ? 'PASS' : 'FAIL';

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Measure', 'Value'], [
                ['Duration', $result['minutes'].' min, '.$result['processes'].' processes'],
                ['Journal mode', $journal],
                ['Writes', number_format($result['writes']).' ('.$result['writes_per_second'].' per second)'],
                ['`database is locked`', $result['database_is_locked']],
                ['Other errors', $result['other_errors'].($stats['first_error'] ? ' (first: '.$stats['first_error'].')' : '')],
                ['Write latency ms', 'p50 '.$pct(0.5).' · p95 '.$result['write_latency_ms']['p95'].' · p99 '.$pct(0.99).' · max '.$result['write_latency_ms']['max']],
                ['Integrity check', $integrity],
            ]);
            $this->line($result['verdict'] === 'PASS'
                ? "<info>PASS</info>: no lock errors and p95 write latency within {$maxP95} ms."
                : "<error>FAIL</error>: any `database is locked`, other error, crashed worker, failed integrity check or p95 above {$maxP95} ms fails the gate.");
        }

        if (!$this->option('keep')) {
            $this->cleanup($file);
        }

        return $result['verdict'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }

    private function cleanup(string $file): void
    {
        foreach ([$file, "{$file}-wal", "{$file}-shm", "{$file}-journal"] as $f) {
            File::delete($f);
        }
    }

    private function seedDevices(array $env, string $file, int $count): void
    {
        $db = new \PDO("sqlite:{$file}");
        $now = date('Y-m-d H:i:s');
        for ($i = 1; $i <= $count; $i++) {
            $db->exec("insert into devices (device_name, ip_address, device_brand_name, created_at, updated_at) values ('Stress {$i}', '10.0.0.{$i}', 'Stress', '{$now}', '{$now}')");
        }
    }

    // --- One role of the load -----------------------------------------------------------

    private function runWorker(string $role, int $id, float $until, float $rate): int
    {
        $latencies = [];
        $stats = ['writes' => 0, 'reads' => 0, 'locked' => 0, 'errors' => 0, 'first_error' => null];
        $tick = 0;

        // The write each role does on a tick: what the real process writes in Control.
        $write = match ($role) {
            // A listener's state and last_seen of its device.
            'listener' => fn () => DB::table('devices')->where('id', $id)->update(['last_seen' => now(), 'updated_at' => now()]),
            // The single history writer: close the last play of a device, open the next.
            'history' => fn () => DB::transaction(function () use (&$tick) {
                $device = ($tick % max(1, (int) DB::table('devices')->count())) + 1;
                DB::table('plays')->where('device_id', $device)->whereNull('ended_at')->update(['ended_at' => now(), 'updated_at' => now()]);
                DB::table('plays')->insert(['device_id' => $device, 'played_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'track_name' => "Song {$tick}", 'artist_name' => 'Stress', 'source_type' => 'stress']);
            }),
            // A default-queue worker: a Setting row (Setting::set is an upsert) and a scrobble-like insert.
            'worker' => function () use ($id, &$tick) {
                DB::table('settings')->upsert([['key' => "stress_{$id}", 'value' => (string) $tick, 'created_at' => now(), 'updated_at' => now()]], ['key'], ['value', 'updated_at']);
            },
            // A web request: mostly reads; every fourth also writes a setting (a toggle, a pinned device).
            'web' => function () use ($id, &$tick, &$stats) {
                DB::table('devices')->orderBy('id')->get();
                DB::table('plays')->orderByDesc('id')->limit(20)->get();
                $stats['reads'] += 2;
                if ($tick % 4 === 0) {
                    DB::table('settings')->upsert([['key' => "stress_web_{$id}", 'value' => (string) $tick, 'created_at' => now(), 'updated_at' => now()]], ['key'], ['value', 'updated_at']);

                    return true;
                }

                return false;
            },
            // The scheduler: alerts:check raises one and resolves it.
            'scheduler' => function () use (&$tick) {
                $key = "stress.{$tick}";
                DB::table('alerts')->insert(['kind' => 'stress', 'key' => $key, 'active_key' => "stress:{$key}", 'severity' => 'info', 'title' => 't', 'message' => 'm', 'raised_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('alerts')->where('key', $key)->update(['resolved_at' => now(), 'active_key' => null]);
            },
            default => throw new \InvalidArgumentException("Unknown role {$role}"),
        };

        // Not all start in lockstep: a listener per second at an offset, like real ones.
        usleep((int) (($id % 10) * 97000));

        while (microtime(true) < $until) {
            $tick++;
            $t = hrtime(true);
            try {
                $counted = $write();
                if ($counted !== false) {
                    $stats['writes']++;
                    $latencies[] = (hrtime(true) - $t) / 1e6;
                }
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                str_contains($message, 'database is locked') || str_contains($message, 'SQLITE_BUSY') ? $stats['locked']++ : $stats['errors']++;
                $stats['first_error'] ??= mb_substr($message, 0, 200);
            }

            usleep((int) max(1000, $rate * 1e6 - (hrtime(true) - $t) / 1e3));
        }

        $this->line(json_encode($stats + ['latencies' => $latencies]));

        return self::SUCCESS;
    }
}
