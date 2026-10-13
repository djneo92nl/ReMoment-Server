<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\AnalysisStage;
use App\Domain\Analysis\Tools;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Support\Facades\Process;

/** The Chromaprint fingerprint (`fpcalc`) of the first two minutes: the same recording gives the same print whatever the encoding or tags. */
class FingerprintStage implements AnalysisStage
{
    public function key(): string
    {
        return 'fingerprint';
    }

    public function version(): string
    {
        return '1';
    }

    public function enabled(): bool
    {
        return Tools::available(Tools::fpcalc(), '-version');
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        $result = Process::timeout(300)->run([Tools::fpcalc(), '-json', '-length', '120', $localPath]);

        if (!$result->successful()) {
            throw new \RuntimeException('fpcalc failed: '.trim(mb_substr($result->errorOutput() ?: $result->output(), 0, 200)));
        }

        $data = json_decode($result->output(), true);

        if (!is_array($data) || empty($data['fingerprint'])) {
            throw new \RuntimeException('fpcalc gave no fingerprint');
        }

        return [
            'fingerprint' => $data['fingerprint'],
            'fingerprint_duration' => (int) round($data['duration'] ?? 0),
            // A new fingerprint invalidates a lookup made for the old one.
            'acoustid_id' => null,
            'acoustid_recording_mbid' => null,
            'acoustid_score' => null,
        ];
    }
}
