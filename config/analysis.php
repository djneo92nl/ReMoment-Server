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

    /** Files `library:analyze` queues per run (the nightly schedule). */
    'batch' => (int) env('ANALYSIS_BATCH', 100),

    /** Files queued right after a file scan. */
    'scan_batch' => (int) env('ANALYSIS_SCAN_BATCH', 25),

    /** Failed attempts of one stage (same version) before the backlog stops retrying it. */
    'max_attempts' => 3,

    /** Peak values stored per track for drawing its waveform. */
    'waveform_points' => (int) env('ANALYSIS_WAVEFORM_POINTS', 400),

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

];
