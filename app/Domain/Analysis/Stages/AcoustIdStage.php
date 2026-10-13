<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\AnalysisStage;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Support\Facades\Http;

/** Looks the stored fingerprint up at AcoustID: the MusicBrainz recording id of the track, without any text matching. */
class AcoustIdStage implements AnalysisStage
{
    public function key(): string
    {
        return 'acoustid';
    }

    public function version(): string
    {
        return '1';
    }

    public function enabled(): bool
    {
        return (bool) config('analysis.acoustid_key');
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        if (!$analysis->fingerprint || !$analysis->fingerprint_duration) {
            // The fingerprint stage failed or hasn't run; the next backlog run tries again.
            throw new \RuntimeException('no fingerprint to look up');
        }

        $response = Http::asForm()->timeout(30)->withHeaders(['User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)'])
            ->post('https://api.acoustid.org/v2/lookup', [
                'client' => config('analysis.acoustid_key'),
                'format' => 'json',
                'meta' => 'recordings',
                'duration' => $analysis->fingerprint_duration,
                'fingerprint' => $analysis->fingerprint,
            ]);

        // A rate limit or outage is an error (retried later); an answer without a match is a result.
        if ($response->status() === 429 || $response->serverError()) {
            $response->throw();
        }
        if ($response->json('status') !== 'ok') {
            throw new \RuntimeException('AcoustID: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        $best = collect($response->json('results', []))
            ->filter(fn ($r) => !empty($r['recordings']) && ($r['score'] ?? 0) >= config('analysis.acoustid_min_score'))
            ->sortByDesc('score')
            ->first();

        return [
            'acoustid_id' => $best['id'] ?? null,
            'acoustid_recording_mbid' => $best['recordings'][0]['id'] ?? null,
            'acoustid_score' => isset($best['score']) ? (float) $best['score'] : null,
        ];
    }
}
