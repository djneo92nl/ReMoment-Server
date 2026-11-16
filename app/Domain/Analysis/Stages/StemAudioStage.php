<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\OnDemandStage;
use App\Domain\Analysis\StemAudio;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The stems of one track as kept AUDIO files for the apps' mixer, from the same stems service and the same separation
 * as the `stems` stage (`POST /stems?audio=mp3|flac` answers a zip of stems.json and the four files). On demand only,
 * on the `stems` queue. The loudness lanes come out of the same run, so a finished run also stores them and counts as
 * `stems` done: asking for both kinds separately would separate the track twice.
 *
 * Stored in `file_analyses.stem_audio` (the manifest, see `StemAudio::store()`); the files are on disk, not in the
 * database. The zip is streamed to a temporary file (a FLAC set of a long track is hundreds of MB).
 */
class StemAudioStage implements OnDemandStage
{
    public function key(): string
    {
        return 'stem_audio';
    }

    /** Bump when the audio format or the service's encoding changes in a way that should recompute kept stems. */
    public function version(): string
    {
        return '1';
    }

    public function enabled(): bool
    {
        return (bool) config('analysis.stems_url');
    }

    public function column(): string
    {
        return 'stem_audio';
    }

    public function serviceUrl(): ?string
    {
        return config('analysis.stems_url') ?: null;
    }

    public function queue(): string
    {
        return config('analysis.stems_queue');
    }

    public function jobTimeout(): int
    {
        return (int) config('analysis.stems_timeout') + 60;
    }

    public function estimateSeconds(?int $trackSeconds): int
    {
        $seconds = max(0, (int) $trackSeconds);

        $estimate = (float) config('analysis.stems_seconds_fixed') + ((float) config('analysis.stems_seconds_per_second') + (float) config('analysis.stem_audio_seconds_per_second')) * $seconds;

        return (int) ceil(round($estimate, 3)); // 5 + 0.55 * 200 is 115.00000000000001 in floats
    }

    public function estimateRamMb(): ?int
    {
        return (int) config('analysis.stems_ram_mb');
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        $stream = fopen($localPath, 'rb');
        $zipPath = tempnam(sys_get_temp_dir(), 'stems-');

        try {
            $response = Http::timeout((int) config('analysis.stems_timeout'))
                ->sink($zipPath)
                ->attach('audio', $stream, basename($file->path) ?: 'audio')
                ->post(rtrim((string) config('analysis.stems_url'), '/').'/stems?'.http_build_query(['audio' => StemAudio::format(), 'bitrate' => (int) config('analysis.stem_audio_bitrate')]));
        } catch (ConnectionException) {
            @unlink($zipPath);
            throw new \RuntimeException('The stems service is not reachable (STEMS_URL) or took longer than STEMS_TIMEOUT.');
        } finally {
            is_resource($stream) && fclose($stream);
        }

        try {
            if ($response->status() === 413) {
                throw new \RuntimeException('Too long for the stems service: '.($response->json('detail') ?: 'over its limit.'));
            }

            if (!$response->successful()) {
                throw new \RuntimeException('The stems service answered HTTP '.$response->status().': '.mb_substr((string) (is_string($detail = $response->json('detail')) ? $detail : ''), 0, 150));
            }

            ['data' => $data, 'manifest' => $manifest] = StemAudio::store($file, $zipPath);
        } finally {
            @unlink($zipPath);
        }

        // The same separation gave the lanes: keep them, and count the `stems` stage as done.
        try {
            $stems = StemsStage::attributes($data);
        } catch (\Throwable $e) {
            StemAudio::delete($file);
            throw $e;
        }
        $analysis->recordSuccess('stems', (new StemsStage)->version());

        return ['stem_audio' => $manifest, 'stems' => $stems];
    }
}
