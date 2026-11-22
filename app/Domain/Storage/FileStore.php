<?php

namespace App\Domain\Storage;

use App\Models\StorageLocation;
use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\FilesystemException;

/**
 * Bytes of one storage location, over its Flysystem disk. Knows nothing about tracks or the files table.
 */
class FileStore
{
    private ?Filesystem $disk = null;

    public function __construct(public readonly StorageLocation $location) {}

    public function disk(): Filesystem
    {
        return $this->disk ??= $this->location->disk();
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function size(string $path): int
    {
        return $this->disk()->size($path);
    }

    public function lastModified(string $path): int
    {
        return $this->disk()->lastModified($path);
    }

    public function mimeType(string $path): ?string
    {
        return $this->disk()->mimeType($path) ?: null;
    }

    /** @param  resource|string  $contents */
    public function put(string $path, $contents): void
    {
        $this->disk()->put($path, $contents);
    }

    /** @return resource */
    public function readStream(string $path)
    {
        return $this->disk()->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /** Removes a directory and everything in it. */
    public function deleteDirectory(string $directory): void
    {
        $this->disk()->deleteDirectory($directory);
    }

    public function move(string $from, string $to): void
    {
        $this->disk()->move($from, $to);
    }

    /** sha256 of the content, read as a stream so a big file is never held in memory. */
    public function hash(string $path): string
    {
        $stream = $this->readStream($path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    /**
     * Every file in the location (path => [size, mtime]), recursively, hidden files skipped.
     *
     * @return \Generator<string, array{0:int,1:int}>
     */
    public function files(string $directory = ''): \Generator
    {
        /** @var \League\Flysystem\FilesystemOperator $driver */
        $driver = $this->disk()->getDriver();

        try {
            foreach ($driver->listContents($directory, true) as $item) {
                if (!$item->isFile() || str_starts_with(basename($item->path()), '.')) {
                    continue;
                }
                yield $item->path() => [(int) $item->fileSize(), (int) $item->lastModified()];
            }
        } catch (FilesystemException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Runs $callback with a local path of the file: the real path on a local disk, a temporary copy
     * (removed afterwards) on a remote one. Set $writable to a callback-visible copy that is
     * always temporary, for edits that are written back with put().
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function withLocalPath(string $path, callable $callback, bool $writable = false): mixed
    {
        if ($this->location->isLocal() && !$writable) {
            return $callback($this->disk()->path($path));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'remoment-').'.'.pathinfo($path, PATHINFO_EXTENSION);
        $out = fopen($tmp, 'wb');
        $in = $this->readStream($path);
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        try {
            return $callback($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Replaces the file with what `$produce(sourcePath, outputPath)` writes, safely: the new content is made next to the
     * original (a hidden file scans skip) on a local disk and renamed over it, so a failure or a crash leaves the original
     * as it was and a reader never sees half a file; on a remote disk it is made in the temp directory and uploaded in one
     * put. The output has the original's extension (tools pick the format from it) and, locally, its permissions.
     *
     * @param  callable(string, string): void  $produce
     */
    public function rewrite(string $path, callable $produce): void
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $this->withLocalPath($path, function (string $source) use ($path, $produce, $extension) {
            $local = $this->location->isLocal();
            $base = $local ? null : tempnam(sys_get_temp_dir(), 'remoment-');
            $output = $local
                ? dirname($source).'/.'.pathinfo($source, PATHINFO_FILENAME).'.edit-'.bin2hex(random_bytes(4)).'.'.$extension
                : $base.'.'.$extension;

            try {
                $produce($source, $output);

                if (!is_file($output) || filesize($output) === 0) {
                    throw new \RuntimeException('The new version of the file was not written; the original was kept.');
                }

                if ($local) {
                    @chmod($output, fileperms($source) & 0777);
                    if (!rename($output, $source)) {
                        throw new \RuntimeException('The new version could not replace the file; the original was kept.');
                    }

                    return;
                }

                $in = fopen($output, 'rb');
                try {
                    $this->put($path, $in);
                } finally {
                    fclose($in);
                }
            } finally {
                @unlink($output);
                if ($base !== null) {
                    @unlink($base);
                }
            }
        });
    }
}
