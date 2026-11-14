<?php

namespace App\Console\Commands\Device;

use App\Domain\Device\DeviceListeners;
use App\Domain\Device\ListenerStatus;
use App\Integrations\Spotify\SpotifyDevice;
use App\Models\Device;
use App\Services\SpotifyTokenService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs every device's listener, one forked child each, from a single booted application.
 *
 * A fresh `php artisan` per listener costs a full framework boot (~50 MB); a fork shares the
 * booted framework copy-on-write, which is what makes a dozen listeners fit on a Raspberry Pi.
 * Adding a brand means registering its listener in config/devices.php, nothing here.
 */
class ListenAllDevices extends Command
{
    protected $signature = 'device:listen {--rescan=15 : Seconds between looking for added, removed, re-addressed or re-driven devices}';

    protected $description = 'Start listeners for all configured devices, and keep them running.';

    private const MAX_BACKOFF_SECONDS = 30;

    private const STOP_GRACE_SECONDS = 5;

    /** @var array<int, array{pid: int, ip: ?string, label: string, started: int}> device id => running child */
    private array $children = [];

    /** @var array<int, array{failures: int, at: int}> device id => when a child that exited may start again */
    private array $restarts = [];

    private bool $stopping = false;

    public function handle(): int
    {
        if (!function_exists('pcntl_fork')) {
            $this->error('The pcntl PHP extension is required (it is part of php-cli on Linux and macOS).');

            return self::FAILURE;
        }

        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, fn () => $this->stopping = true);
        }

        $nextScan = 0;

        while (!$this->stopping) {
            try {
                if (time() >= $nextScan) {
                    $this->reconcile();
                    $nextScan = time() + max(1, (int) $this->option('rescan'));
                }

                $this->handleRestartRequests();
                $this->reap();
                $this->restartDue();
            } catch (QueryException $e) {
                // "Too many connections" or a restarting database must not take the parent down: that
                // stops every running listener and supervisor then restarts it in a loop. Try again later.
                Log::warning("Listener supervisor could not reach the database: {$e->getMessage()}");
                $this->releaseConnections();
                $nextScan = time() + max(5, (int) $this->option('rescan'));
                sleep(2);
            }

            usleep(200000);
        }

        $this->info('Stopping listeners...');
        $this->stopAll();

        return self::SUCCESS;
    }

    /** Starts listeners for new devices, stops those of removed or re-addressed ones. */
    private function reconcile(): void
    {
        $wanted = $this->wantedDevices();

        foreach ($this->children as $id => $child) {
            $device = $wanted[$id] ?? null;

            if ($device === null) {
                $this->warn("[{$child['label']}] device is gone or has no listener, stopping it.");
                $this->stop($id);
                ListenerStatus::transition($id, ListenerStatus::STOPPED, 'The device was removed or its driver has no listener.');
                unset($this->restarts[$id]);
            } elseif ($device->ip_address !== $child['ip']) {
                $this->info("[{$child['label']}] address changed, restarting its listener.");
                $this->stop($id);
                $this->restarts[$id] = ['failures' => 0, 'at' => 0];
            } elseif ($device->device_driver !== $child['driver']) {
                // E.g. a TV paired with philips:pair moves from the UPnP driver to its own.
                $this->info("[{$child['label']}] driver changed, restarting its listener.");
                $this->stop($id);
                $this->restarts[$id] = ['failures' => 0, 'at' => 0];
            }
        }

        foreach ($wanted as $id => $device) {
            if (!isset($this->children[$id]) && ($this->restarts[$id]['at'] ?? 0) <= time()) {
                $this->start($device);
            }
        }
    }

    /** @return array<int, Device> */
    private function wantedDevices(): array
    {
        // The Spotify device only exists once an account is connected; connecting later is picked up here.
        if (app(SpotifyTokenService::class)->isConnected()) {
            SpotifyDevice::findOrProvision();
        }

        return Device::all()
            ->filter(fn (Device $device) => DeviceListeners::supports($device))
            ->keyBy('id')
            ->all();
    }

    private function start(Device $device): void
    {
        // Nothing may be open at fork time: a database or Redis connection shared with a child
        // would have two processes writing to one socket.
        $this->releaseConnections();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->error("[{$device->device_name}] could not fork a listener.");

            return;
        }

        if ($pid === 0) {
            $this->runChild($device);
        }

        $this->children[$device->id] = [
            'pid' => $pid,
            'ip' => $device->ip_address,
            'driver' => $device->device_driver,
            'label' => $device->device_name,
            'started' => time(),
        ];
        $this->info("[{$device->device_name}] listener started (pid {$pid}).");
        ListenerStatus::transition($device->id, ListenerStatus::RUNNING, null, [
            'pid' => $pid,
            'ip' => $device->ip_address,
            'failures' => $this->restarts[$device->id]['failures'] ?? 0,
        ]);
    }

    /** The health page's restart button: stop the child (or skip its backoff) and start it again right away. */
    private function handleRestartRequests(): void
    {
        $ids = ListenerStatus::pullRestartRequests(array_unique([...array_keys($this->children), ...array_keys($this->restarts)]));

        foreach ($ids as $id) {
            $label = $this->children[$id]['label'] ?? "device {$id}";
            $this->info("[{$label}] restart requested.");

            if (isset($this->children[$id])) {
                $this->stop($id);
            }

            $this->restarts[$id] = ['failures' => 0, 'at' => 0];
        }
    }

    /** Runs in the forked child and never returns. */
    private function runChild(Device $device): never
    {
        $this->children = [];
        $this->restarts = [];
        @cli_set_process_title('remoment listen '.$device->device_name);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, fn () => exit(0));
        }

        try {
            // A listener may decline (Spotify without an account), which ends the child quietly.
            DeviceListeners::for($device)?->listen((string) $device->id);
            exit(0);
        } catch (\Throwable $e) {
            Log::error("Listener for [{$device->device_name}] crashed: {$e->getMessage()}", ['exception' => $e]);
            ListenerStatus::error($device->id, $e);
            exit(1);
        }
    }

    /** Notes children that exited and schedules their restart, with a growing delay while they keep failing. */
    private function reap(): void
    {
        foreach ($this->children as $id => $child) {
            if (pcntl_waitpid($child['pid'], $status, WNOHANG) !== $child['pid']) {
                continue;
            }

            unset($this->children[$id]);

            // A child that ran for a while was healthy: its next failure starts the backoff over.
            $failures = time() - $child['started'] > 60 ? 0 : ($this->restarts[$id]['failures'] ?? 0);
            $delay = min(2 ** $failures, self::MAX_BACKOFF_SECONDS);
            $this->restarts[$id] = ['failures' => $failures + 1, 'at' => time() + $delay];

            $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 'signal '.pcntl_wtermsig($status);
            $this->warn("[{$child['label']}] listener exited ({$code}), restarting in {$delay}s.");
            ListenerStatus::transition($id, ListenerStatus::RETRYING, "The listener process ended ({$code}) after ".(time() - $child['started']).'s.', [
                'ip' => $child['ip'],
                'failures' => $failures + 1,
                'retry_at' => time() + $delay,
            ]);
        }
    }

    private function restartDue(): void
    {
        foreach ($this->restarts as $id => $restart) {
            if (isset($this->children[$id]) || $restart['at'] > time()) {
                continue;
            }

            $device = Device::find($id);

            if ($device && DeviceListeners::supports($device)) {
                $this->start($device);
            } else {
                unset($this->restarts[$id]);
            }
        }
    }

    private function stop(int $id): void
    {
        $pid = $this->children[$id]['pid'];
        unset($this->children[$id]);

        $this->terminate([$pid]);
    }

    private function stopAll(): void
    {
        $pids = array_column($this->children, 'pid');
        $this->children = [];

        $this->terminate($pids);
    }

    /**
     * SIGTERM to all at once, one shared grace period, then SIGKILL for the stragglers: a child
     * blocked in a network call only sees the signal once that call returns.
     *
     * @param  list<int>  $pids
     */
    private function terminate(array $pids): void
    {
        foreach ($pids as $pid) {
            posix_kill($pid, SIGTERM);
        }

        for ($waited = 0; $pids !== [] && $waited < self::STOP_GRACE_SECONDS * 10; $waited++) {
            $pids = array_filter($pids, fn (int $pid) => pcntl_waitpid($pid, $status, WNOHANG) !== $pid);
            $pids === [] || usleep(100000);
        }

        foreach ($pids as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    private function releaseConnections(): void
    {
        DB::disconnect();
        // purge() without a name only drops `default`; the cache store runs on its own `cache` connection,
        // and a socket left open here is shared with the child (replies read by the wrong process show up
        // as "unserialize(): Error at offset 0 of 2 bytes" in RedisStore).
        foreach (array_keys((array) config('database.redis')) as $name) {
            if (is_array(config("database.redis.{$name}"))) {
                app('redis')->purge($name);
            }
        }

        foreach (array_keys((array) config('cache.stores')) as $store) {
            app('cache')->forgetDriver($store);
        }
    }
}
