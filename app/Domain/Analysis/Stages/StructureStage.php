<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\DecodeBranch;
use App\Domain\Analysis\DecodedStage;
use App\Domain\Analysis\DecodePass;
use App\Domain\Analysis\OnDemandStage;
use App\Domain\Analysis\Tools;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The sections of one track (workstream 16.2), found by the structure service (docker/structure, see
 * docs/architecture/structure-service.md): [{start, end, label, confidence}] in seconds, `label` a repetition letter
 * or null. ON DEMAND only: it is not in `AudioAnalysis::stages()`, so no nightly run, backlog or progress total
 * ever counts it; `AnalyzeTrackDeep` runs it for one file.
 *
 * Stored in `file_analyses.sections` as { method, service_version, tempo, reason, items }; `items` is empty with a
 * `reason` (`too_short`, `no_structure`) when the service found no sections.
 */
class StructureStage implements DecodedStage, OnDemandStage
{
    public function key(): string
    {
        return 'structure';
    }

    /** Bump when the service's method or parameters change in a way that should recompute stored sections. */
    public function version(): string
    {
        return '1';
    }

    public function enabled(): bool
    {
        return (bool) config('analysis.structure_url') && Tools::available(Tools::ffmpeg());
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        return DecodePass::runAlone($this, $file, $localPath, $analysis);
    }

    public function branch(File $file): DecodeBranch
    {
        $rate = (int) config('analysis.structure_decode_rate');

        return new DecodeBranch("{in}aformat=channel_layouts=mono,aresample={$rate},aformat=sample_fmts=s16{out}", ['-c:a', 'pcm_s16le', '-f', 'wav']);
    }

    public function fromDecode(File $file, string $localPath, FileAnalysis $analysis, DecodePass $pass): array
    {
        $stream = fopen($pass->file($this->key()), 'rb');

        try {
            $response = Http::timeout((int) config('analysis.structure_timeout'))
                ->attach('audio', $stream, 'audio.wav')
                ->post(rtrim(config('analysis.structure_url'), '/').'/structure');
        } catch (ConnectionException) {
            throw new \RuntimeException('The structure service is not reachable (STRUCTURE_URL).');
        } finally {
            is_resource($stream) && fclose($stream);
        }

        if ($response->status() === 413) {
            throw new \RuntimeException('Too long for the structure service: '.($response->json('detail') ?: 'over its duration limit.'));
        }

        if (!$response->successful()) {
            throw new \RuntimeException('The structure service answered HTTP '.$response->status().': '.mb_substr((string) ($response->json('detail') ?? ''), 0, 150));
        }

        return ['sections' => self::attributes($response->json() ?? [])];
    }

    /**
     * The service's JSON as the stored envelope.
     *
     * @return array{method: string, service_version: int, tempo: ?float, reason: ?string, items: list<array{start: float, end: float, label: ?string, confidence: float}>}
     */
    public static function attributes(array $data): array
    {
        if (!isset($data['sections']) || !is_array($data['sections'])) {
            throw new \RuntimeException('The structure service answered without sections.');
        }

        $items = [];
        foreach ($data['sections'] as $s) {
            if (!is_array($s) || !isset($s['start'], $s['end']) || !is_numeric($s['start']) || !is_numeric($s['end'])) {
                continue;
            }
            $items[] = [
                'start' => round((float) $s['start'], 2),
                'end' => round((float) $s['end'], 2),
                'label' => isset($s['label']) && $s['label'] !== '' ? (string) $s['label'] : null,
                'confidence' => round((float) ($s['confidence'] ?? 0), 2),
            ];
        }

        return [
            'method' => (string) ($data['method'] ?? 'novelty-v1'),
            'service_version' => (int) ($data['version'] ?? 1),
            'tempo' => isset($data['tempo']) && is_numeric($data['tempo']) ? round((float) $data['tempo'], 1) : null,
            'reason' => $items === [] ? (string) ($data['reason'] ?? 'no_structure') : null,
            'items' => $items,
        ];
    }
}
