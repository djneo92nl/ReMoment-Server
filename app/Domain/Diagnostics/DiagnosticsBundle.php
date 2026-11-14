<?php

namespace App\Domain\Diagnostics;

use App\Domain\Alerts\Alerts;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\ListenerStatus;
use App\Domain\Health\ExternalServices;
use App\Domain\Health\Heartbeat;
use App\Domain\Health\QueueInspector;
use App\Domain\Health\Queues;
use App\Domain\Profile\Profile;
use App\Domain\System\InstalledVersion;
use App\Domain\System\UpgradeHistory;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * One zip a user attaches to an issue (docs/architecture/diagnostics.md). Everything goes through the Redactor; there is no
 * option to include secrets. Nothing is uploaded: the file is written locally or streamed to the admin who asked.
 */
class DiagnosticsBundle
{
    public const LOG_LINES = 500;

    public function __construct(private readonly Redactor $redactor) {}

    public static function make(): self
    {
        return new self(Redactor::forInstall());
    }

    /** Writes the zip to $path (a file path) and returns the files in it, name => bytes. */
    public function write(string $path): array
    {
        $files = $this->files();

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot write {$path}.");
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return array_map('strlen', $files);
    }

    /** @return array<string, string> file name => redacted content */
    public function files(): array
    {
        $sections = [
            'system.json' => $this->safely(fn () => $this->system()),
            'heartbeats.json' => $this->safely(fn () => $this->heartbeats()),
            'queues.json' => $this->safely(fn () => $this->queues()),
            'devices.json' => $this->safely(fn () => $this->devices()),
            'alerts.json' => $this->safely(fn () => $this->alerts()),
            'upgrade-history.json' => $this->safely(fn () => UpgradeHistory::all()),
        ];

        if (Profile::allows('library')) {
            $sections['external-services.json'] = $this->safely(fn () => $this->services());
        }

        $files = ['README.txt' => $this->readme()];
        foreach ($sections as $name => $data) {
            $files[$name] = $this->json($data);
        }
        $files['laravel.log'] = $this->log();

        return $files;
    }

    private function json(mixed $data): string
    {
        return (string) json_encode($this->redactor->value($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** A section that fails reports the failure instead of sinking the whole bundle. */
    private function safely(\Closure $build): mixed
    {
        try {
            return $build();
        } catch (\Throwable $e) {
            return ['unavailable' => class_basename($e).': '.strtok($e->getMessage(), "\n")];
        }
    }

    private function system(): array
    {
        $driver = config('database.default');
        try {
            $dbVersion = DB::selectOne($driver === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v')->v ?? null;
        } catch (\Throwable) {
            $dbVersion = null;
        }

        $extensions = [];
        foreach (get_loaded_extensions() as $extension) {
            $extensions[$extension] = phpversion($extension) ?: null;
        }
        ksort($extensions);

        return [
            'generated_at' => now()->toIso8601String(),
            'app' => [
                'version' => InstalledVersion::current(),
                'recorded_version' => InstalledVersion::recorded(),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'laravel' => app()->version(),
            ],
            'profile' => Profile::current(),
            'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY, 'extensions' => $extensions],
            'database' => ['driver' => $driver, 'server_version' => $dbVersion, 'pending_migrations' => $this->pendingMigrations()],
            'queue_driver' => config('queue.default'),
            'cache_driver' => config('cache.default'),
        ];
    }

    private function pendingMigrations(): ?int
    {
        try {
            $files = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn ($f) => basename($f, '.php'));
            $ran = DB::table('migrations')->pluck('migration');

            return $files->diff($ran)->count();
        } catch (\Throwable) {
            return null;
        }
    }

    private function heartbeats(): array
    {
        $beat = fn (string $key) => Heartbeat::last($key)?->toIso8601String();
        $queues = [];
        foreach (Queues::heartbeat() as $queue) {
            $queues[$queue] = $beat(Queues::heartbeatKey($queue));
        }

        return ['scheduler' => $beat(Heartbeat::SCHEDULER), 'queue_worker' => $beat(Heartbeat::QUEUE), 'queues' => $queues];
    }

    private function queues(): array
    {
        $inspector = app(QueueInspector::class);
        $pending = $inspector->pending();

        return [
            'pending' => $pending ? ['total' => $pending['total'], 'by_job' => array_map(fn ($g) => [
                'job' => $g['job'], 'count' => $g['count'], 'waiting' => $g['waiting'], 'running' => $g['running'], 'delayed' => $g['delayed'],
                'oldest' => $g['oldest']?->toIso8601String(),
            ], $pending['groups'])] : null,
            'queues' => array_map(fn ($q) => ['queue' => $q['queue'], 'state' => $q['state'], 'waiting' => $q['waiting'], 'delayed' => $q['delayed'], 'running' => $q['running']], $inspector->queues() ?? []),
            // Class and the first line of the exception only; the payload (which can hold file names and ids) is never read.
            'failed' => array_map(fn ($f) => ['job' => $f['job'], 'queue' => $f['queue'], 'message' => $f['message'], 'at' => $f['at']->toIso8601String()], $inspector->failed(50)),
        ];
    }

    private function devices(): array
    {
        $devices = [];
        foreach (Device::orderBy('id')->get() as $device) {
            $listener = ListenerStatus::get($device->id);
            $devices[] = [
                'id' => $device->id,
                'brand' => $device->device_brand_name,
                'model' => $device->device_product_type,
                'driver' => $device->device_driver,
                'state' => DeviceCache::getState($device->id)?->value ?? null,
                'last_seen' => DeviceCache::getLastSeen($device->id) ?: null,
                'listener_running' => DeviceCache::isListenerRunning($device->id),
                'listener' => $listener ? ['state' => $listener['state'], 'since' => $listener['since'] ?? null, 'failures' => $listener['failures'] ?? null, 'reason' => $listener['reason'] ?? null, 'last_error' => $listener['last_error'] ?? null] : null,
                // Names of the stored facts only, never their values (they hold addresses, ids and tokens).
                'meta_keys' => DeviceMeta::where('device_id', $device->id)->orderBy('key')->pluck('key')->all(),
            ];
        }

        return $devices;
    }

    private function alerts(): array
    {
        $shape = fn (Alert $a) => ['kind' => $a->kind, 'severity' => $a->severity, 'title' => $a->title, 'message' => $a->message, 'device_id' => $a->device_id,
            'raised_at' => $a->raised_at?->toIso8601String(), 'resolved_at' => $a->resolved_at?->toIso8601String(), 'acknowledged_at' => $a->acknowledged_at?->toIso8601String()];

        return [
            'active' => Alerts::active()->map($shape)->values()->all(),
            'recent' => Alert::whereNotNull('resolved_at')->latest('raised_at')->limit(50)->get()->map($shape)->all(),
        ];
    }

    private function services(): array
    {
        $services = app(ExternalServices::class);

        return ['checked_at' => $services->checkedAt()?->toIso8601String(), 'results' => $services->all()];
    }

    private function log(): string
    {
        $path = storage_path('logs/laravel.log');
        if (!is_readable($path)) {
            return "No log file.\n";
        }

        return $this->redactor->text(implode("\n", $this->tail($path, self::LOG_LINES))."\n");
    }

    /** @return list<string> the last $lines lines, reading from the end so a big log is not loaded */
    private function tail(string $path, int $lines): array
    {
        $handle = fopen($path, 'rb');
        $size = filesize($path);
        $buffer = '';
        $pos = $size;
        while ($pos > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = min(8192, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $buffer = fread($handle, $read).$buffer;
        }
        fclose($handle);

        return array_slice(explode("\n", rtrim($buffer, "\n")), -$lines);
    }

    private function readme(): string
    {
        $library = Profile::allows('library') ? '' : "\nThe profile is Control: there is no library, so no external-service results are included.\n";

        return <<<TXT
        ReMoment diagnostics bundle, made {$this->now()}.

        Attach this zip to an issue. It is made on your server and never uploaded by ReMoment.

        INCLUDED
        - system.json: app version and profile, PHP version and extensions, database driver and server version, number of pending migrations.
        - heartbeats.json: when the scheduler and the queue workers last reported.
        - queues.json: pending jobs per class, queue states, failed jobs (job class, queue and the first line of the error only).
        - devices.json: per device id, brand, model, driver, state, last seen, listener status, and the NAMES of its stored facts (not their values).
        - alerts.json: active alerts and the 50 most recent resolved ones.
        - external-services.json: the last health results of external services (when the profile has a library).
        - upgrade-history.json: the last upgrades and their results.
        - laravel.log: the last 500 lines of the application log, redacted.
        {$library}
        NOT INCLUDED, in any case and without any option
        - IP addresses, MAC addresses and e-mail addresses (replaced by [ip], [mac], [email]).
        - Passwords, tokens, API keys, client secrets, session keys, bearer headers, admin token hashes, APP_KEY, anything from .env.
        - Spotify, Last.fm, Discogs and other service credentials.
        - Device names, device settings values, library contents (tracks, albums, artists, playlists, play history), files and covers.

        Redaction is automatic and pattern based. Skim the files before you post them in public; if you find something private, black it out and tell us so we can improve the redactor.
        TXT."\n";
    }

    private function now(): string
    {
        return now()->toIso8601String();
    }
}
