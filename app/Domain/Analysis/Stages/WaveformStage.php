<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\AnalysisStage;
use App\Domain\Analysis\Tools;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Support\Facades\Process;

/**
 * The waveform of a file: `analysis.waveform_points` peak values (0–100, relative to the file's own loudest
 * sample), from the audio decoded by ffmpeg to 4 kHz mono. Enough to draw a track's shape; not for editing.
 */
class WaveformStage implements AnalysisStage
{
    /** Samples per second the file is decoded to; peaks need no more. */
    private const RATE = 4000;

    public function key(): string
    {
        return 'waveform';
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
            Tools::ffmpeg(), '-v', 'error', '-i', $localPath, '-vn', '-ac', '1', '-ar', (string) self::RATE, '-f', 's16le', '-',
        ]);

        if (!$result->successful()) {
            throw new \RuntimeException('ffmpeg could not decode the file: '.trim(mb_substr($result->errorOutput(), 0, 200)));
        }

        return ['waveform' => self::peaks($result->output(), (int) config('analysis.waveform_points'))];
    }

    /**
     * Peaks of signed 16-bit little-endian mono PCM: the loudest sample of each of $points equal parts,
     * scaled so the loudest of all is 100. Fewer parts when there are fewer samples.
     *
     * @return list<int>
     */
    public static function peaks(string $pcm, int $points): array
    {
        $samples = intdiv(strlen($pcm), 2);

        if ($samples === 0) {
            throw new \RuntimeException('the file has no audio to draw');
        }

        $points = max(1, min($points, $samples));
        $peaks = [];

        for ($i = 0; $i < $points; $i++) {
            $from = intdiv($i * $samples, $points);
            $to = intdiv(($i + 1) * $samples, $points);
            $values = unpack('s*', substr($pcm, $from * 2, max(1, $to - $from) * 2));
            $peaks[] = max(array_map('abs', $values));
        }

        $loudest = max($peaks) ?: 1;

        return array_map(fn (int $peak) => (int) round($peak / $loudest * 100), $peaks);
    }
}
