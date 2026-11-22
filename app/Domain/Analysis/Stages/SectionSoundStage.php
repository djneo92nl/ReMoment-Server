<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\DecodeBranch;
use App\Domain\Analysis\DecodedStage;
use App\Domain\Analysis\DecodePass;
use App\Domain\Analysis\NeedsSidecarModel;
use App\Domain\Analysis\OnDemandStage;
use App\Domain\Analysis\Tools;
use App\Domain\Similar\Embeddings;
use App\Domain\Similar\SectionEmbeddings;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The sound of each section of one track (workstream 16.5): the Essentia sidecar's `POST /section-embeddings` averages
 * the Discogs-EffNet patches inside every stored section into one vector, which goes to `section_embeddings` (the
 * search is `App\Domain\Library\SimilarSections`). Needs the sections of the track first (kind `sections`); ON DEMAND
 * only. The manifest in `file_analyses.section_sound` is { model, items: [{start, end}] }.
 */
class SectionSoundStage implements DecodedStage, NeedsSidecarModel, OnDemandStage
{
    public function key(): string
    {
        return 'section_sound';
    }

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
        return 'effnet';
    }

    public function column(): string
    {
        return 'section_sound';
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
        return (int) ceil((float) config('analysis.section_sound_seconds_fixed') + (float) config('analysis.section_sound_seconds_per_second') * max(0, (int) $trackSeconds));
    }

    public function estimateRamMb(): ?int
    {
        return null;
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        // Fail before decoding anything when there is nothing to embed.
        if (empty($analysis->sections['items'])) {
            throw new \RuntimeException('This track has no sections yet: analyse its sections first.');
        }

        return DecodePass::runAlone($this, $file, $localPath, $analysis);
    }

    public function branch(File $file): DecodeBranch
    {
        $rate = (int) config('analysis.ml_decode_rate');

        return new DecodeBranch("{in}aformat=channel_layouts=mono,aresample={$rate},aformat=sample_fmts=s16{out}", ['-c:a', 'pcm_s16le', '-f', 'wav']);
    }

    public function fromDecode(File $file, string $localPath, FileAnalysis $analysis, DecodePass $pass): array
    {
        $items = array_values($analysis->sections['items'] ?? []);
        $ranges = array_map(fn ($s) => [(float) $s['start'], (float) $s['end']], $items);
        $stream = fopen($pass->file($this->key()), 'rb');

        try {
            $response = Http::timeout((int) config('analysis.essentia_timeout'))
                ->attach('audio', $stream, 'audio.wav')
                ->post(rtrim((string) config('analysis.essentia_url'), '/').'/section-embeddings', ['sections' => json_encode($ranges)]);
        } catch (ConnectionException) {
            throw new \RuntimeException('The analysis sidecar is not reachable (ANALYSIS_ESSENTIA_URL).');
        } finally {
            is_resource($stream) && fclose($stream);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('The analysis sidecar answered HTTP '.$response->status().': '.mb_substr((string) ($response->json('detail') ?? ''), 0, 150));
        }

        $answered = $response->json('sections');
        if (!is_array($answered) || count($answered) !== count($items)) {
            throw new \RuntimeException('The analysis sidecar answered a different number of sections than were sent.');
        }

        $sections = [];
        foreach ($items as $i => $item) {
            $sections[] = ['start' => $item['start'], 'end' => $item['end'], 'label' => $item['label'] ?? null, 'embedding' => $answered[$i]['embedding'] ?? null];
        }

        $model = (string) ($response->json('model') ?? Embeddings::MODEL);
        if (!SectionEmbeddings::store($file, $analysis->content_hash ?? $file->hash, $sections, $model)) {
            throw new \RuntimeException('The analysis sidecar answered a section embedding that cannot be used.');
        }

        return ['section_sound' => ['model' => $model, 'items' => array_map(fn ($s) => ['start' => $s['start'], 'end' => $s['end']], $sections)]];
    }
}
