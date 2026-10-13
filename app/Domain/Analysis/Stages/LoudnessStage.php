<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\AnalysisStage;
use App\Domain\Analysis\Tools;
use App\Domain\Storage\AudioTags;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Support\Facades\Process;

/**
 * EBU R128 loudness (integrated LUFS, loudness range, true peak) from ffmpeg's `ebur128` filter, which also
 * proves the file decodes, plus the ReplayGain values tagged in the file.
 */
class LoudnessStage implements AnalysisStage
{
    public function key(): string
    {
        return 'loudness';
    }

    public function version(): string
    {
        return '1';
    }

    public function enabled(): bool
    {
        return Tools::available(Tools::ffmpeg());
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        $result = Process::timeout(600)->run([
            Tools::ffmpeg(), '-hide_banner', '-nostats', '-i', $localPath, '-vn', '-af', 'ebur128=peak=true', '-f', 'null', '-',
        ]);

        if (!$result->successful()) {
            throw new \RuntimeException('ffmpeg could not decode the file: '.$this->lastLine($result->errorOutput()));
        }

        $loudness = self::parseSummary($result->errorOutput());
        $tags = AudioTags::read($localPath) ?? [];

        return $loudness + [
            'replaygain_track_gain' => $tags['replaygain_track_gain'] ?? null,
            'replaygain_album_gain' => $tags['replaygain_album_gain'] ?? null,
        ];
    }

    /**
     * The values of the `Summary:` block ffmpeg prints last (the running lines before it are ignored).
     *
     * @return array{loudness_lufs: float, loudness_range: ?float, true_peak: ?float}
     */
    public static function parseSummary(string $stderr): array
    {
        $summary = substr($stderr, (int) strrpos($stderr, 'Summary:'));
        $number = fn (string $pattern) => preg_match($pattern, $summary, $m) ? (float) $m[1] : null;

        $integrated = $number('/\bI:\s+(-?\d+(?:\.\d+)?)\s+LUFS/');
        if ($integrated === null) {
            throw new \RuntimeException('ffmpeg reported no loudness (silent or empty audio?)');
        }

        return [
            'loudness_lufs' => $integrated,
            'loudness_range' => $number('/\bLRA:\s+(-?\d+(?:\.\d+)?)\s+LU/'),
            'true_peak' => $number('/\bPeak:\s+(-?\d+(?:\.\d+)?)\s+dBFS/'),
        ];
    }

    private function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return end($lines) ?: 'no output';
    }
}
