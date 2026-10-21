<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The audio analysis of one stored file; see App\Domain\Analysis\AudioAnalysis. */
class FileAnalysis extends Model
{
    /** Result columns, cleared when the file's content changes. */
    public const RESULTS = [
        'loudness_lufs', 'loudness_range', 'true_peak', 'replaygain_track_gain', 'replaygain_album_gain',
        'fingerprint', 'fingerprint_duration', 'acoustid_id', 'acoustid_recording_mbid', 'acoustid_score',
        'bpm', 'key', 'mode', 'key_strength', 'danceability', 'arousal', 'valence', 'instrumental',
        'mood_tags', 'genre_tags', 'waveform',
        'spectral_cutoff_hz', 'lossless_suspect', 'measured_bit_depth', 'clipped_samples', 'clipping_suspect', 'is_mono',
        'phase_issue', 'lead_silence_ms', 'trail_silence_ms', 'decode_warnings',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'loudness_lufs' => 'float', 'loudness_range' => 'float', 'true_peak' => 'float',
        'replaygain_track_gain' => 'float', 'replaygain_album_gain' => 'float',
        'acoustid_score' => 'float', 'bpm' => 'float', 'key_strength' => 'float', 'danceability' => 'float',
        'arousal' => 'float', 'valence' => 'float', 'instrumental' => 'float',
        'mood_tags' => 'array', 'genre_tags' => 'array', 'waveform' => 'array', 'stages' => 'array', 'errors' => 'array',
        'lossless_suspect' => 'float', 'clipping_suspect' => 'boolean', 'is_mono' => 'boolean', 'phase_issue' => 'boolean',
        'decode_warnings' => 'array', 'analyzed_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** Forgets every result and stage, for a file whose content changed. */
    public function resetFor(?string $hash): void
    {
        $this->forceFill(array_fill_keys(self::RESULTS, null) + ['content_hash' => $hash, 'stages' => [], 'errors' => []]);
    }

    public function stageVersion(string $stage): ?string
    {
        return $this->stages[$stage]['version'] ?? null;
    }

    public function recordSuccess(string $stage, string $version): void
    {
        $errors = $this->errors ?? [];
        unset($errors[$stage]);

        $this->stages = array_merge($this->stages ?? [], [$stage => ['version' => $version, 'at' => now()->toIso8601String()]]);
        $this->errors = $errors;
    }

    public function recordError(string $stage, string $version, string $message): void
    {
        $previous = $this->errors[$stage] ?? null;
        $attempts = ($previous && ($previous['version'] ?? null) === $version) ? ($previous['attempts'] ?? 0) + 1 : 1;

        $this->errors = array_merge($this->errors ?? [], [$stage => [
            'message' => mb_substr($message, 0, 300),
            'version' => $version,
            'attempts' => $attempts,
            'at' => now()->toIso8601String(),
        ]]);
    }

    /** True when the stage failed this many times already at this version, so the backlog leaves it alone. */
    public function gaveUp(string $stage, string $version): bool
    {
        $error = $this->errors[$stage] ?? null;

        return $error !== null && ($error['version'] ?? null) === $version
            && ($error['attempts'] ?? 0) >= config('analysis.max_attempts');
    }
}
