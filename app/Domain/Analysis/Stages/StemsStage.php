<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\OnDemandStage;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The stems of one track (workstream 16.4) as loudness lanes, found by the stems service (docker/stems, Demucs, see
 * docs/architecture/stems-service.md): one integer dB curve per stem (vocals, drums, bass, other), the vocal ratio and the
 * instrumental ranges. ON DEMAND only, on its own `stems` queue: it is not in `AudioAnalysis::stages()`, so no nightly
 * run, backlog or progress total ever counts it. The service reads the original file itself (it decodes with its own
 * ffmpeg), so nothing is decoded here. No stem audio is kept anywhere.
 *
 * Stored in `file_analyses.stems` as { method, service_version, rate_hz, vocal_ratio, instrumental, lanes }.
 */
class StemsStage implements OnDemandStage
{
    public const LANES = ['vocals', 'drums', 'bass', 'other'];

    public function key(): string
    {
        return 'stems';
    }

    /** Bump when the service's model or settings change in a way that should recompute stored stems. */
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
        return 'stems';
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
        return (int) ceil((float) config('analysis.stems_seconds_fixed') + (float) config('analysis.stems_seconds_per_second') * max(0, (int) $trackSeconds));
    }

    public function estimateRamMb(): ?int
    {
        return (int) config('analysis.stems_ram_mb');
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        $stream = fopen($localPath, 'rb');

        try {
            $response = Http::timeout((int) config('analysis.stems_timeout'))
                ->attach('audio', $stream, basename($file->path) ?: 'audio')
                ->post(rtrim((string) config('analysis.stems_url'), '/').'/stems');
        } catch (ConnectionException) {
            throw new \RuntimeException('The stems service is not reachable (STEMS_URL) or took longer than STEMS_TIMEOUT.');
        } finally {
            is_resource($stream) && fclose($stream);
        }

        if ($response->status() === 413) {
            throw new \RuntimeException('Too long for the stems service: '.($response->json('detail') ?: 'over its limit.'));
        }

        if (!$response->successful()) {
            throw new \RuntimeException('The stems service answered HTTP '.$response->status().': '.mb_substr((string) ($response->json('detail') ?? ''), 0, 150));
        }

        return ['stems' => self::attributes($response->json() ?? [])];
    }

    /**
     * The service's JSON as the stored envelope: every lane a list of integer dB (clamped to -80..0) of the same length.
     *
     * @return array{method: string, service_version: int, rate_hz: int, vocal_ratio: float, instrumental: list<array{start: float, end: float}>, lanes: array<string, list<int>>}
     */
    public static function attributes(array $data): array
    {
        $stems = $data['stems'] ?? null;
        if (!is_array($stems)) {
            throw new \RuntimeException('The stems service answered without stems.');
        }

        $lanes = [];
        foreach (self::LANES as $lane) {
            if (!isset($stems[$lane]) || !is_array($stems[$lane]) || $stems[$lane] === []) {
                throw new \RuntimeException("The stems service answered without the {$lane} lane.");
            }
            $lanes[$lane] = array_map(fn ($v) => max(-80, min(0, (int) round((float) $v))), array_values($stems[$lane]));
        }

        if (count(array_unique(array_map('count', $lanes))) !== 1) {
            throw new \RuntimeException('The stems service answered lanes of different lengths.');
        }

        $instrumental = [];
        foreach ((array) ($data['instrumental'] ?? []) as $range) {
            if (is_array($range) && isset($range['start'], $range['end']) && is_numeric($range['start']) && is_numeric($range['end'])) {
                $instrumental[] = ['start' => round((float) $range['start'], 2), 'end' => round((float) $range['end'], 2)];
            }
        }

        return [
            'method' => (string) ($data['method'] ?? 'htdemucs'),
            'service_version' => (int) ($data['version'] ?? 1),
            'rate_hz' => max(1, (int) ($data['rate_hz'] ?? 2)),
            'vocal_ratio' => round(max(0.0, min(1.0, (float) ($data['vocal_ratio'] ?? 0))), 3),
            'instrumental' => $instrumental,
            'lanes' => $lanes,
        ];
    }
}
