<?php

namespace App\Domain\Storage;

use App\Domain\Analysis\Tools;
use App\Jobs\TranscodeProxy;
use App\Models\Device;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\TrackFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Smaller copies ("proxies") of the server's own audio files, for players that cannot decode the original.
 * A proxy is addressed by the original's content hash and a profile from `config/proxies.php`
 * (`proxies/{profile}/{hash}.{ext}` on the private disk), so a changed file gets a new proxy and nothing
 * here touches the files table. They are made on demand by `TranscodeProxy` and pruned oldest first.
 */
final class Proxies
{
    private const WAIT_TTL_SECONDS = 3600;

    public static function enabled(): bool
    {
        return (bool) config('proxies.enabled') && Tools::available(Tools::ffmpeg());
    }

    /** @return array<string, array{mime: string, extension: string, args: string[]}> */
    public static function profiles(): array
    {
        return config('proxies.profiles', []);
    }

    public static function known(?string $profile): bool
    {
        return $profile !== null && isset(self::profiles()[$profile]);
    }

    public static function mime(string $profile): ?string
    {
        return self::profiles()[$profile]['mime'] ?? null;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(config('proxies.disk'));
    }

    /** The file a track plays from when nothing smaller is asked for: its primary original, if it is there. */
    public static function original(Track $track): ?File
    {
        $links = $track->trackFiles()->with('file.location')->get()
            ->filter(fn (TrackFile $link) => $link->role === TrackFile::ORIGINAL && $link->file?->status === File::PRESENT);

        return ($links->first(fn (TrackFile $link) => $link->is_primary) ?? $links->first())?->file;
    }

    public static function path(File $original, string $profile): string
    {
        $extension = self::profiles()[$profile]['extension'];

        return config('proxies.directory')."/{$profile}/".substr($original->hash ?: sha1($original->path), 0, 32).".{$extension}";
    }

    /** The path of the ready proxy of this track on the proxy disk, or null when there is none yet. */
    public static function readyPath(Track $track, string $profile): ?string
    {
        if (!self::known($profile) || !($original = self::original($track))) {
            return null;
        }

        $path = self::path($original, $profile);

        return self::disk()->exists($path) ? $path : null;
    }

    /**
     * True when the proxy is ready. Otherwise queues it (once) and returns false; $deviceId, when given,
     * is told through `PlayerDriver::proxyReady()` as soon as it is.
     */
    public static function request(Track $track, string $profile, ?int $deviceId = null): bool
    {
        if (self::readyPath($track, $profile) !== null) {
            return true;
        }

        $original = self::original($track);
        if (!$original || !self::known($profile) || !self::enabled()) {
            return false;
        }

        if ($deviceId !== null) {
            $key = self::waitKey($original->id, $profile);
            $waiting = Cache::get($key, []);
            $waiting[$deviceId] = $track->id;
            Cache::put($key, $waiting, self::WAIT_TTL_SECONDS);
        }

        TranscodeProxy::dispatch($original, $profile);

        return false;
    }

    /** Makes the proxy of a file; a no-op when it exists. Throws when ffmpeg fails. */
    public static function generate(File $original, string $profile): string
    {
        $target = self::path($original, $profile);
        $disk = self::disk();

        if ($disk->exists($target)) {
            return $target;
        }

        $profileConfig = self::profiles()[$profile];

        (new FileStore($original->location))->withLocalPath($original->path, function (string $source) use ($profileConfig, $target, $disk) {
            $temp = tempnam(sys_get_temp_dir(), 'remoment-proxy-').'.'.$profileConfig['extension'];

            try {
                $result = Process::timeout((int) config('proxies.timeout'))
                    ->run([Tools::ffmpeg(), '-y', '-nostdin', '-v', 'error', '-i', $source, ...$profileConfig['args'], $temp]);

                if (!$result->successful() || !is_file($temp) || filesize($temp) === 0) {
                    throw new \RuntimeException('ffmpeg failed: '.trim($result->errorOutput() ?: 'no output'));
                }

                $stream = fopen($temp, 'rb');
                $disk->put($target, $stream);
                is_resource($stream) && fclose($stream);
            } finally {
                @unlink($temp);
            }
        });

        self::prune();

        return $target;
    }

    /** Tells the devices that waited for this proxy that it is ready (or failed). */
    public static function settle(File $original, string $profile, bool $ready): void
    {
        $key = self::waitKey($original->id, $profile);
        $waiting = Cache::pull($key, []);

        foreach ($waiting as $deviceId => $trackId) {
            $driver = Device::find($deviceId)?->driver;

            if (!$driver || !method_exists($driver, 'proxyReady')) {
                continue;
            }

            try {
                $ready ? $driver->proxyReady((int) $trackId) : $driver->proxyFailed((int) $trackId);
            } catch (\Throwable $e) {
                Log::warning("Proxy {$profile} for file {$original->id}: device {$deviceId} not told: {$e->getMessage()}");
            }
        }
    }

    /** Deletes the oldest proxies until the rest fits `proxies.max_mb`. Returns how many went. */
    public static function prune(): int
    {
        $limit = (int) config('proxies.max_mb') * 1024 * 1024;
        if ($limit <= 0) {
            return 0;
        }

        $disk = self::disk();
        $files = [];
        $total = 0;

        foreach ($disk->allFiles(config('proxies.directory')) as $path) {
            $size = $disk->size($path);
            $files[$path] = [$disk->lastModified($path), $size];
            $total += $size;
        }

        uasort($files, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $deleted = 0;
        foreach ($files as $path => [, $size]) {
            if ($total <= $limit) {
                break;
            }
            $disk->delete($path);
            $total -= $size;
            $deleted++;
        }

        return $deleted;
    }

    private static function waitKey(int $fileId, string $profile): string
    {
        return "proxy:wait:{$fileId}:{$profile}";
    }
}
