<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audio analysis of the server's own music files
    |--------------------------------------------------------------------------
    |
    | Off by default: it is CPU heavy and runs on its own queue (`analysis`),
    | so playback history and artwork never wait behind it. See
    | docs/architecture/audio-analysis.md.
    |
    */

    'enabled' => (bool) env('ANALYSIS_ENABLED', false),

    'queue' => env('ANALYSIS_QUEUE', 'analysis'),

    /** Seconds one file may take (all stages); keep `DB_QUEUE_RETRY_AFTER` above it. */
    'timeout' => (int) env('ANALYSIS_TIMEOUT', 900),

    /**
     * Hours of work the nightly `library:analyze` queues: it takes as many files as fit at the measured time per
     * file (see AnalysisActivity::secondsPerFile), the rest follows the next night.
     */
    'night_hours' => (float) env('ANALYSIS_NIGHT_HOURS', 6),

    /** Files `library:analyze` queues per run until the time per file has been measured (a fresh install). */
    'batch' => (int) env('ANALYSIS_BATCH', 100),

    /** Files queued right after a file scan. */
    'scan_batch' => (int) env('ANALYSIS_SCAN_BATCH', 25),

    /** Failed attempts of one stage (same version) before the backlog stops retrying it. */
    'max_attempts' => 3,

    /** Values stored per track for drawing its waveform. */
    'waveform_points' => (int) env('ANALYSIS_WAVEFORM_POINTS', 400),

    /*
    | Waveform contrast (WaveformStage): bucket RMS in dB is stretched per track from its 5th to its 99th
    | percentile; the span between them stays within these many dB. The local-loudness deviation (moving
    | average of this many seconds, times a share) is strongest (`deviation_max`) at a peak-to-loudness
    | ratio (true peak minus LUFS) of `deviation_plr_low` dB or less and 0 from `deviation_plr_high` up.
    */
    'contrast_min_span' => 6.0,
    'contrast_max_span' => 30.0,
    'deviation_window_seconds' => 4.0,
    'deviation_max' => 0.7,
    'deviation_plr_low' => 8.0,
    'deviation_plr_high' => 14.0,

    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),

    'fpcalc' => env('FPCALC_BINARY', 'fpcalc'),

    /*
    | AcoustID (free key from acoustid.org): turns a fingerprint into the
    | MusicBrainz recording id. Without a key fingerprints are only stored.
    */
    'acoustid_key' => env('ACOUSTID_API_KEY'),

    'acoustid_min_score' => (float) env('ACOUSTID_MIN_SCORE', 0.9),

    /*
    | The Essentia sidecar (docker/analysis, compose profile "analysis"):
    | BPM, key, danceability and, when built with ML, mood and genre.
    | Set `essentia_ml` when it runs the TensorFlow models, so files analysed
    | without them are analysed again.
    */
    'essentia_url' => env('ANALYSIS_ESSENTIA_URL'),

    'essentia_ml' => (bool) env('ANALYSIS_ESSENTIA_ML', false),

    /** A mood classifier score below this is not kept as a mood tag (scores run 0–1; the sidecar already drops those under 0.5). */
    'mood_min_score' => (float) env('ANALYSIS_MOOD_MIN_SCORE', 0.7),

    'essentia_timeout' => (int) env('ANALYSIS_ESSENTIA_TIMEOUT', 600),

    /*
    | The structure service (docker/structure, compose profile "structure"): sections of ONE track, on demand
    | only (AnalyzeTrackDeep), never part of the nightly run. Unset = deep analysis is not available.
    | docs/architecture/structure-service.md.
    */
    'structure_url' => env('STRUCTURE_URL'),

    /** Seconds a request to the structure service may take (a track needs 1-10 s on a desktop, more on a Pi). */
    'structure_timeout' => (int) env('STRUCTURE_TIMEOUT', 240),

    /** Sample rate of the WAV sent to the service (it resamples to its own rate anyway). */
    'structure_decode_rate' => (int) env('STRUCTURE_DECODE_RATE', 22050),

    /** Estimate shown before a sections run: seconds per second of audio, plus a fixed part. Measured on an Apple M5 Pro; a Pi is several times slower. */
    'structure_seconds_per_second' => (float) env('STRUCTURE_SECONDS_PER_SECOND', 0.012),
    'structure_seconds_fixed' => (float) env('STRUCTURE_SECONDS_FIXED', 2),

];
