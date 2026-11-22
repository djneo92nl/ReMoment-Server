<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\DecodeBranch;
use App\Domain\Analysis\DecodedStage;
use App\Domain\Analysis\DecodePass;
use App\Domain\Analysis\NeedsSidecarModel;
use App\Domain\Analysis\OnDemandStage;
use App\Domain\Analysis\Tools;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mood over time of one track (workstream 16.3): arousal and valence per window of a few seconds, from the Essentia
 * sidecar's `POST /curve` (the DEAM predictions of the MusiCNN patches that `/analyze` averages into one pair). ON DEMAND
 * only, like sections: not in `AudioAnalysis::stages()`.
 *
 * Stored in `file_analyses.mood_curve` as { method, service_version, window, arousal, valence }, the two lists integers
 * 0-100 (a few hundred bytes: 3 s windows make 80 points for a 4-minute track).
 */
class MoodStage implements DecodedStage, NeedsSidecarModel, OnDemandStage
{
    /** Points kept at most (a 10 s window over 5 hours); a longer answer is refused. */
    private const MAX_POINTS = 4000;

    public function key(): string
    {
        return 'mood';
    }

    /** Bump when the sidecar's model or windowing changes in a way that should recompute stored curves. */
    public function version(): string
    {
        return '1';
    }

    public function enabled(): bool
    {
        return (bool) config('analysis.essentia_url') && Tools::available(Tools::ffmpeg());
    }

    public function sidecarModel(): string
    {
        return 'deam';
    }

    public function column(): string
    {
        return 'mood_curve';
    }

    public function serviceUrl(): ?string
    {
        return config('analysis.essentia_url') ?: null;
    }

    public function queue(): string
    {
        return config('analysis.queue');
    }

    public function jobTimeout(): int
    {
        return (int) config('analysis.essentia_timeout') + 60;
    }

    public function estimateSeconds(?int $trackSeconds): int
    {
        return (int) ceil((float) config('analysis.mood_seconds_fixed') + (float) config('analysis.mood_seconds_per_second') * max(0, (int) $trackSeconds));
    }

    public function estimateRamMb(): ?int
    {
        return null;
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        return DecodePass::runAlone($this, $file, $localPath, $analysis);
    }

    public function branch(File $file): DecodeBranch
    {
        $rate = (int) config('analysis.ml_decode_rate');

        return new DecodeBranch("{in}aformat=channel_layouts=mono,aresample={$rate},aformat=sample_fmts=s16{out}", ['-c:a', 'pcm_s16le', '-f', 'wav']);
    }

    public function fromDecode(File $file, string $localPath, FileAnalysis $analysis, DecodePass $pass): array
    {
        $stream = fopen($pass->file($this->key()), 'rb');

        try {
            $response = Http::timeout((int) config('analysis.essentia_timeout'))
                ->attach('audio', $stream, 'audio.wav')
                ->post(rtrim((string) config('analysis.essentia_url'), '/').'/curve', ['window' => (float) config('analysis.mood_window_seconds')]);
        } catch (ConnectionException) {
            throw new \RuntimeException('The analysis sidecar is not reachable (ANALYSIS_ESSENTIA_URL).');
        } finally {
            is_resource($stream) && fclose($stream);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('The analysis sidecar answered HTTP '.$response->status().': '.mb_substr((string) ($response->json('detail') ?? ''), 0, 150));
        }

        return ['mood_curve' => self::attributes($response->json() ?? [])];
    }

    /**
     * The sidecar's JSON as the stored envelope: the two curves as integers 0-100.
     *
     * @return array{method: string, service_version: int, window: float, arousal: list<int>, valence: list<int>}
     */
    public static function attributes(array $data): array
    {
        $arousal = $data['arousal'] ?? null;
        $valence = $data['valence'] ?? null;

        if (!is_array($arousal) || !is_array($valence) || $arousal === [] || count($arousal) !== count($valence) || count($arousal) > self::MAX_POINTS) {
            throw new \RuntimeException('The analysis sidecar answered without a usable mood curve.');
        }

        $percent = function (mixed $v): int {
            if (!is_int($v) && !is_float($v) || !is_finite((float) $v)) {
                throw new \RuntimeException('The analysis sidecar answered a mood curve with a value that is not a number.');
            }

            return (int) round(max(0.0, min(1.0, (float) $v)) * 100);
        };

        return [
            'method' => (string) ($data['method'] ?? 'deam-msd-musicnn-2'),
            'service_version' => (int) ($data['version'] ?? 1),
            'window' => round(max(0.1, (float) ($data['window'] ?? config('analysis.mood_window_seconds'))), 2),
            'arousal' => array_map($percent, array_values($arousal)),
            'valence' => array_map($percent, array_values($valence)),
        ];
    }
}
