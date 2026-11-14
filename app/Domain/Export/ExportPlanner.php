<?php

namespace App\Domain\Export;

use App\Domain\Storage\Proxies;
use App\Models\File;
use App\Models\Media\Track;
use InvalidArgumentException;

/**
 * Decides, per track, how an export gets its file: copied as it is, transcoded, or skipped (no own file).
 * Decision only: nothing is read from disk, run or written. The rules are in config/export.php and
 * docs/architecture/export.md; they use `files.codec` and `files.bitrate`, so no file is probed again.
 */
final class ExportPlanner
{
    public const COPY = 'copy';

    public const TRANSCODE = 'transcode';

    public const SKIP = 'skip';

    private const LOSSLESS = ['flac', 'alac', 'wav', 'aiff', 'wavpack', 'ape', 'tta'];

    /** @return list<string> */
    public static function profiles(): array
    {
        return array_keys(config('export.profiles'));
    }

    /**
     * @param  iterable<Track>  $tracks  in export order
     */
    public function plan(iterable $tracks, ?string $profile = null, ?string $loudness = null): ExportPlan
    {
        $mode = ExportLoudness::normalise($loudness ?? 'off');
        $items = [];
        $entries = [];
        foreach ($tracks as $track) {
            $file = Proxies::original($track);
            $items[] = $file ? $this->decide($file, $profile, $track->id) : new ExportItem($track->id, null, self::SKIP, 'no_own_file', null, 0);
            if ($file && end($items)->action === self::TRANSCODE) {
                $entries[$track->id] = ['track' => $track, 'file' => $file];
            }
        }

        // Loudness is only baked in where a file is transcoded; a copy stays bit-exact.
        $leveled = 0;
        $unleveled = 0;
        if ($mode !== 'off') {
            $gains = (new ExportLoudness)->gains($entries, $mode);
            foreach ($items as $i => $item) {
                if ($item->action !== self::TRANSCODE) {
                    continue;
                }
                $gain = $gains[$item->trackId] ?? null;
                $gain === null ? $unleveled++ : $leveled++;
                if ($gain !== null) {
                    $items[$i] = $item->withGain($gain);
                }
            }
        }

        return $this->summarise($items, $this->profile($profile)['key'], $mode, $leveled, $unleveled);
    }

    /** The decision for one file; `$trackId` only labels the result. */
    public function decide(File $file, ?string $profile = null, ?int $trackId = null): ExportItem
    {
        $p = $this->profile($profile);
        $codec = self::codec($file);
        $lossless = in_array($codec, self::LOSSLESS, true) || str_starts_with($codec, 'pcm_');
        $kbps = self::kbps($file->bitrate);
        $size = (int) $file->size;
        $ceiling = $p['ceiling'];

        if ($ceiling === null) {
            return new ExportItem($trackId, $file->id, self::COPY, 'highest_available', null, $size, $codec);
        }

        $target = fn (int $rate): array => [
            'codec' => $p['target'], 'bitrate' => $rate, 'sample_rate' => $p['sample_rate'], 'stereo' => $p['stereo'], 'gain_db' => null,
        ];
        $transcode = fn (string $reason, int $rate): ExportItem => new ExportItem(
            $trackId, $file->id, self::TRANSCODE, $reason, $target($rate),
            $this->estimate($file, $rate, (string) $p['target']), $codec, $kbps,
        );

        if ($lossless) {
            return $transcode('lossless', $ceiling);
        }
        if (!in_array($codec, $p['copy_codecs'], true)) {
            // Another lossy codec (Vorbis, Opus, AAC for a profile that wants MP3): never above its own bitrate.
            return $transcode('codec_not_accepted', $kbps ? min($kbps, $ceiling) : $ceiling);
        }
        if ($kbps === null) {
            return $transcode('unknown_bitrate', $ceiling);
        }
        if ($kbps > $ceiling) {
            return $transcode('above_ceiling', $ceiling);
        }
        if ($p['sample_rate'] !== null && $file->sample_rate && (int) $file->sample_rate !== $p['sample_rate']) {
            return $transcode('sample_rate', $kbps);
        }
        if ($p['stereo'] && (int) $file->channels > 2) {
            return $transcode('channels', $kbps);
        }

        return new ExportItem($trackId, $file->id, self::COPY, 'within_ceiling', null, $size, $codec, $kbps);
    }

    /** @param list<ExportItem> $items */
    private function summarise(array $items, string $profile, string $loudness = 'off', int $leveled = 0, int $unleveled = 0): ExportPlan
    {
        $part = (int) round((float) config('export.part_gb') * 1024 ** 3);
        $parts = 0;
        $fill = 0;
        $total = 0;
        foreach ($items as $item) {
            if ($item->action === self::SKIP) {
                continue;
            }
            $total += $item->estimatedBytes;
            if ($parts === 0 || $fill + $item->estimatedBytes > $part) {
                $parts++;
                $fill = 0;
            }
            $fill += $item->estimatedBytes;
        }

        $max = (int) round((float) config('export.max_gb') * 1024 ** 3);
        $dir = (int) round((float) config('export.dir_max_gb') * 1024 ** 3);
        $exceeds = [];
        if ($max > 0 && $total > $max) {
            $exceeds[] = 'max_gb';
        }
        if ($dir > 0 && $total > $dir) {
            $exceeds[] = 'dir_max_gb';
        }

        $count = fn (string $a) => count(array_filter($items, fn (ExportItem $i) => $i->action === $a));

        return new ExportPlan(
            $profile, $items, count($items), $count(self::COPY), $count(self::TRANSCODE), $count(self::SKIP),
            $total, $parts, $exceeds, $loudness, $leveled, $unleveled,
        );
    }

    /** @return array{key: string, ceiling: ?int, copy_codecs: ?array, sample_rate: ?int, stereo: bool, id3v23: bool, target: ?string} */
    private function profile(?string $profile): array
    {
        $key = $profile ?: config('export.default_profile');
        $config = config("export.profiles.{$key}");
        if (!is_array($config)) {
            throw new InvalidArgumentException("Unknown export profile [{$key}].");
        }

        return ['key' => $key] + $config;
    }

    private static function codec(File $file): string
    {
        $codec = strtolower((string) ($file->codec ?: $file->extension));

        return match (true) {
            in_array($codec, ['mp3', 'mp2', 'lame', 'mpeg'], true) => 'mp3',
            in_array($codec, ['aac', 'm4a', 'mp4'], true) => 'aac',
            $codec === 'ogg' => 'vorbis',
            $codec === 'libopus' => 'opus',
            default => $codec,
        };
    }

    /** Files report kbit/s (320) or bit/s (320000), like Track::bitrateKbps(). */
    private static function kbps(?int $bitrate): ?int
    {
        if (!$bitrate || $bitrate <= 0) {
            return null;
        }

        return $bitrate > 10000 ? (int) round($bitrate / 1000) : $bitrate;
    }

    /** Bytes of a CBR result: duration × bitrate (× the container overhead of the format); without a duration, the size of the source as the next best guess. */
    private function estimate(File $file, int $kbps, string $format = 'mp3'): int
    {
        if ($file->duration) {
            return (int) round($file->duration * $kbps * 125 * ExportFormats::overhead($format));
        }

        return (int) $file->size;
    }
}
