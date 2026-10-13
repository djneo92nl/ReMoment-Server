<?php

namespace App\Domain\Analysis\Stages;

use App\Domain\Analysis\AnalysisStage;
use App\Domain\Analysis\Tools;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * BPM, key, danceability and (when the sidecar runs the ML models) mood, arousal/valence, instrumentalness
 * and genre, computed by the Essentia sidecar (docker/analysis). The audio goes over as a mono 44.1 kHz WAV
 * made here with ffmpeg, so it works for local and remote files and the sidecar needs no access to the music.
 */
class EssentiaStage implements AnalysisStage
{
    /** Keys are stored with sharps only, so "Bb" and "A#" are one key. */
    private const FLATS = ['Db' => 'C#', 'Eb' => 'D#', 'Gb' => 'F#', 'Ab' => 'G#', 'Bb' => 'A#'];

    public function key(): string
    {
        return 'essentia';
    }

    public function version(): string
    {
        return '1'.(config('analysis.essentia_ml') ? '+ml' : '');
    }

    public function enabled(): bool
    {
        return (bool) config('analysis.essentia_url') && Tools::available(Tools::ffmpeg());
    }

    public function run(File $file, string $localPath, FileAnalysis $analysis): array
    {
        $wav = tempnam(sys_get_temp_dir(), 'remoment-essentia-');

        try {
            $decoded = Process::timeout(600)->run([
                Tools::ffmpeg(), '-y', '-v', 'error', '-i', $localPath, '-vn', '-ac', '1', '-ar', '44100', '-f', 'wav', $wav,
            ]);
            if (!$decoded->successful()) {
                throw new \RuntimeException('ffmpeg could not decode the file: '.trim(mb_substr($decoded->errorOutput(), 0, 200)));
            }

            $stream = fopen($wav, 'rb');
            try {
                $response = Http::timeout(config('analysis.essentia_timeout'))
                    ->attach('audio', $stream, 'audio.wav')
                    ->post(rtrim(config('analysis.essentia_url'), '/').'/analyze');
            } finally {
                is_resource($stream) && fclose($stream);
            }
        } finally {
            @unlink($wav);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('Essentia sidecar answered HTTP '.$response->status());
        }

        return self::attributes($response->json() ?? []);
    }

    /**
     * The sidecar's JSON as `file_analyses` attributes. Features it did not compute (no ML models) stay out,
     * so a later run with ML does not lose them and a run without does not blank them.
     *
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $unit = fn ($v) => is_numeric($v) ? max(0.0, min(1.0, (float) $v)) : null;
        $tags = fn ($list, float $minimum = 0.0) => array_values(array_map(
            fn ($t) => ['name' => (string) $t['name'], 'score' => round((float) ($t['score'] ?? 0), 3)],
            array_filter((array) $list, fn ($t) => is_array($t) && !empty($t['name']) && (float) ($t['score'] ?? 0) >= $minimum),
        ));
        $moods = $tags($data['mood_tags'] ?? [], (float) config('analysis.mood_min_score'));

        $mode = isset($data['mode']) ? strtolower((string) $data['mode']) : null;

        return array_filter([
            'bpm' => isset($data['bpm']) && is_numeric($data['bpm']) ? round((float) $data['bpm'], 1) : null,
            'key' => isset($data['key']) ? (self::FLATS[$data['key']] ?? substr((string) $data['key'], 0, 3)) : null,
            'mode' => in_array($mode, ['major', 'minor'], true) ? $mode : null,
            'key_strength' => $unit($data['key_strength'] ?? null),
            'danceability' => $unit($data['danceability'] ?? null),
            'arousal' => $unit($data['arousal'] ?? null),
            'valence' => $unit($data['valence'] ?? null),
            'instrumental' => $unit($data['instrumental'] ?? null),
            'mood_tags' => $moods ?: null,
            'genre_tags' => !empty($data['genre_tags']) ? $tags($data['genre_tags']) : null,
        ], fn ($v) => $v !== null);
    }
}
