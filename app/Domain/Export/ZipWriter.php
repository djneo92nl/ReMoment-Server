<?php

namespace App\Domain\Export;

use RuntimeException;

/**
 * A small streaming zip writer: store mode only (MP3s do not compress), written to a file as it goes, so a zip of
 * many gigabytes never sits in memory. The CRC of a file is read first (`hash_file`), so entries need no data
 * descriptor and every reader can list them. Zip64 is used per entry above 4 GB and for the directory when it
 * holds more than 65535 entries or sits beyond 4 GB. Names are UTF-8 (flag bit 11).
 */
final class ZipWriter
{
    private const MAX32 = 0xFFFFFFFF;

    /** @var resource */
    private $handle;

    private int $offset = 0;

    /** @var list<array{name: string, crc: int, size: int, offset: int, time: int, date: int}> */
    private array $entries = [];

    private bool $closed = false;

    public function __construct(public readonly string $path)
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write zip {$path}.");
        }
        $this->handle = $handle;
    }

    /** Bytes written so far (the part's size once closed). */
    public function size(): int
    {
        return $this->offset;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function addFile(string $name, string $sourcePath, ?int $mtime = null): void
    {
        $crc = hash_file('crc32b', $sourcePath);
        $size = filesize($sourcePath);
        if ($crc === false || $size === false) {
            throw new RuntimeException("Cannot read {$sourcePath}.");
        }
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException("Cannot read {$sourcePath}.");
        }

        try {
            $this->writeEntry($name, hexdec($crc), $size, $mtime ?? (filemtime($sourcePath) ?: time()), $source);
        } finally {
            fclose($source);
        }
    }

    public function addString(string $name, string $contents, ?int $mtime = null): void
    {
        $source = fopen('php://memory', 'r+b');
        fwrite($source, $contents);
        rewind($source);

        try {
            $this->writeEntry($name, crc32($contents), strlen($contents), $mtime ?? time(), $source);
        } finally {
            fclose($source);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $start = $this->offset;
        foreach ($this->entries as $e) {
            $this->writeCentral($e);
        }
        $cdSize = $this->offset - $start;
        $count = count($this->entries);

        if ($count > 0xFFFF || $start > self::MAX32 || $cdSize > self::MAX32) {
            $eocd64 = $this->offset;
            $this->write(pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, $count, $count, $cdSize, $start));
            $this->write(pack('VVPV', 0x07064B50, 0, $eocd64, 1));
            $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, min($count, 0xFFFF), min($count, 0xFFFF), min($cdSize, self::MAX32), min($start, self::MAX32), 0));
        } else {
            $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, $cdSize, $start, 0));
        }

        fclose($this->handle);
        $this->closed = true;
    }

    /** Closes the handle without writing the directory (a failed export); the caller removes the file. */
    public function abort(): void
    {
        if (!$this->closed) {
            fclose($this->handle);
            $this->closed = true;
        }
    }

    /** @param  resource  $source */
    private function writeEntry(string $name, int $crc, int $size, int $mtime, $source): void
    {
        if ($this->closed) {
            throw new RuntimeException('The zip is closed.');
        }
        [$time, $date] = self::dos($mtime);
        $entry = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $this->offset, 'time' => $time, 'date' => $date];

        $zip64 = $size >= self::MAX32;
        $extra = $zip64 ? pack('vvPP', 0x0001, 16, $size, $size) : '';
        $this->write(pack(
            'VvvvvvVVVvv', 0x04034B50, $zip64 ? 45 : 20, 0x0800, 0, $time, $date, $crc,
            $zip64 ? self::MAX32 : $size, $zip64 ? self::MAX32 : $size, strlen($name), strlen($extra),
        ).$name.$extra);

        $written = stream_copy_to_stream($source, $this->handle);
        if ($written !== $size) {
            throw new RuntimeException("Wrote {$written} of {$size} bytes for {$name}.");
        }
        $this->offset += $size;
        $this->entries[] = $entry;
    }

    /** @param  array{name: string, crc: int, size: int, offset: int, time: int, date: int}  $e */
    private function writeCentral(array $e): void
    {
        $bigSize = $e['size'] >= self::MAX32;
        $bigOffset = $e['offset'] >= self::MAX32;

        $extra = '';
        if ($bigSize) {
            $extra .= pack('PP', $e['size'], $e['size']);
        }
        if ($bigOffset) {
            $extra .= pack('P', $e['offset']);
        }
        if ($extra !== '') {
            $extra = pack('vv', 0x0001, strlen($extra)).$extra;
        }

        $this->write(pack(
            'VvvvvvvVVVvvvvvVV', 0x02014B50, 45, ($bigSize || $bigOffset) ? 45 : 20, 0x0800, 0, $e['time'], $e['date'], $e['crc'],
            $bigSize ? self::MAX32 : $e['size'], $bigSize ? self::MAX32 : $e['size'], strlen($e['name']), strlen($extra), 0, 0, 0, 0,
            $bigOffset ? self::MAX32 : $e['offset'],
        ).$e['name'].$extra);
    }

    private function write(string $bytes): void
    {
        if (fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException("Cannot write to {$this->path} (disk full?).");
        }
        $this->offset += strlen($bytes);
    }

    /** @return array{0: int, 1: int} DOS time and date (1980 or later) */
    private static function dos(int $timestamp): array
    {
        $d = getdate(max($timestamp, 315532800));

        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
}
