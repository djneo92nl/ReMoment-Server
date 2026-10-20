<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\DecodeBranch;
use App\Domain\Analysis\DecodedStage;
use App\Domain\Analysis\DecodePass;
use App\Domain\Analysis\Tools;
use App\Models\File;
use App\Models\FileAnalysis;

/**
 * The waveform of a file: `analysis.waveform_points` peak values (0–100, relative to the file's own loudest
 * sample), from the audio decoded by ffmpeg to 4 kHz mono. Enough to draw a track's shape; not for editing.
 */
class WaveformStage implements DecodedStage
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
        return DecodePass::runAlone($this, $file, $localPath, $analysis);
    }

    public function branch(File $file): DecodeBranch
    {
        return new DecodeBranch('{in}aformat=channel_layouts=mono,aresample='.self::RATE.',aformat=sample_fmts=s16{out}', ['-f', 's16le']);
    }

    public function fromDecode(File $file, string $localPath, FileAnalysis $analysis, DecodePass $pass): array
    {
        return ['waveform' => self::peaks((string) file_get_contents($pass->file($this->key())), (int) config('analysis.waveform_points'))];
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
