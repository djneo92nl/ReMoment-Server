<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Export from the library: download tracks for an MP3 player or a car radio
    |--------------------------------------------------------------------------
    |
    | `App\Domain\Export\ExportPlanner` decides per file whether it is copied or transcoded for a profile.
    | A profile: `ceiling` (kbit/s, a lossy file at or below it is copied, anything above is transcoded to it;
    | null = no ceiling, copy everything), `copy_codecs` (the lossy codecs a file may keep), `sample_rate`
    | (fixed output rate; null = keep, at most 48 kHz), `stereo` (force at most 2 channels), `id3v23` (ID3v2.3
    | tags only) and `target` (the codec a transcode produces). A file is never upsampled: a transcode of a
    | lossy file uses the lower of its own bitrate and the ceiling. See docs/architecture/export.md.
    |
    */

    'default_profile' => env('EXPORT_DEFAULT_PROFILE', 'mp3-320'),

    'profiles' => [
        'highest' => ['label' => 'Highest available', 'ceiling' => null, 'copy_codecs' => null, 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => null],
        'mp3-320' => ['label' => 'MP3, max 320 kbit/s', 'ceiling' => 320, 'copy_codecs' => ['mp3', 'aac'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'mp3'],
        'mp3-256' => ['label' => 'MP3, max 256 kbit/s', 'ceiling' => 256, 'copy_codecs' => ['mp3', 'aac'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'mp3'],
        'mp3-192' => ['label' => 'MP3, max 192 kbit/s', 'ceiling' => 192, 'copy_codecs' => ['mp3', 'aac'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'mp3'],
        'mp3-128' => ['label' => 'MP3, max 128 kbit/s', 'ceiling' => 128, 'copy_codecs' => ['mp3', 'aac'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'mp3'],
        // Older head units: only MP3 is kept, 44.1 kHz stereo, ID3v2.3.
        'compat' => ['label' => 'Compatibility (older head units)', 'ceiling' => 320, 'copy_codecs' => ['mp3'], 'sample_rate' => 44100, 'stereo' => true, 'id3v23' => true, 'target' => 'mp3'],
        // AAC (.m4a): MP3 and AAC files at or below the ceiling are copied (no lossy-to-lossy generation loss).
        'aac-256' => ['label' => 'AAC (m4a), max 256 kbit/s', 'ceiling' => 256, 'copy_codecs' => ['aac', 'mp3'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'aac'],
        'aac-192' => ['label' => 'AAC (m4a), max 192 kbit/s', 'ceiling' => 192, 'copy_codecs' => ['aac', 'mp3'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'aac'],
        'aac-128' => ['label' => 'AAC (m4a), max 128 kbit/s', 'ceiling' => 128, 'copy_codecs' => ['aac', 'mp3'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'aac'],
        // Opus (.opus, Ogg): only Opus files are kept; Opus is efficient, so its ceilings are lower than MP3's.
        'opus-160' => ['label' => 'Opus, max 160 kbit/s', 'ceiling' => 160, 'copy_codecs' => ['opus'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'opus'],
        'opus-128' => ['label' => 'Opus, max 128 kbit/s', 'ceiling' => 128, 'copy_codecs' => ['opus'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'opus'],
        'opus-96' => ['label' => 'Opus, max 96 kbit/s', 'ceiling' => 96, 'copy_codecs' => ['opus'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'opus'],
        'opus-64' => ['label' => 'Opus, max 64 kbit/s', 'ceiling' => 64, 'copy_codecs' => ['opus'], 'sample_rate' => null, 'stereo' => false, 'id3v23' => false, 'target' => 'opus'],
    ],

    /*
    | Transcode formats (`target` of a profile): the ffmpeg encoders that may make them (first available wins),
    | file extension, MIME type, container overhead on top of duration x bitrate (for the estimate) and a
    | compatibility note shown in the dialog and the estimate. `App\Domain\Export\ExportFormats` probes
    | `ffmpeg -encoders` (cached) to see which are really there. AAC uses ffmpeg's native `aac` encoder;
    | `EXPORT_AAC_ENCODER=libfdk_aac` prefers libfdk_aac when ffmpeg has it (it is rarely built in).
    */
    'formats' => [
        'mp3' => ['label' => 'MP3', 'encoders' => ['libmp3lame'], 'extension' => 'mp3', 'mime' => 'audio/mpeg', 'overhead' => 1.0, 'note' => 'Plays everywhere: cars, old and new MP3 players.'],
        'aac' => ['label' => 'AAC', 'encoders' => [env('EXPORT_AAC_ENCODER', 'aac'), 'aac'], 'extension' => 'm4a', 'mime' => 'audio/mp4', 'overhead' => 1.01, 'note' => 'Plays on phones, Apple devices and most newer players and cars; some older MP3 players and head units do not play .m4a.'],
        'opus' => ['label' => 'Opus', 'encoders' => ['libopus'], 'extension' => 'opus', 'mime' => 'audio/ogg', 'overhead' => 1.02, 'note' => 'Best quality for its size, but many cars and MP3 players do not play Opus. Use it for phones and players you know support it.'],
    ],

    /** Loudness levelling at transcode (`options.loudness`): the target of ReplayGain 2 in LUFS, and the safety left below 0 dBFS in dB. */
    'loudness_reference_lufs' => -18.0,
    'loudness_margin_db' => 0.3,
    'loudness_max_gain_db' => 12.0,

    /** Most one export may hold, in GB; above it the export is refused with its estimate. */
    'max_gb' => (float) env('EXPORT_MAX_GB', 8),

    /** Size of one zip in GB (FAT32 holds files up to 4 GB). */
    'part_gb' => (float) env('EXPORT_PART_GB', 4),

    /** Hours a finished export is kept. */
    'keep_hours' => (int) env('EXPORT_KEEP_HOURS', 24),

    /** Size the whole exports/ directory may take together, in GB; the oldest go first. */
    'dir_max_gb' => (float) env('EXPORT_DIR_MAX_GB', 16),

    /** Disk (private) the exports are written to, under `exports/{id}/`. */
    'disk' => env('EXPORT_DISK', 'local'),

    /** Queue of the job: the `heavy` worker, one at a time. */
    'queue' => env('EXPORT_QUEUE', 'heavy'),

    /** Seconds one export job may run (a large artist on a Pi 3 takes a long while). */
    'timeout' => (int) env('EXPORT_TIMEOUT', 14400),

    /** Seconds one ffmpeg run (one file) may take. */
    'file_timeout' => (int) env('EXPORT_FILE_TIMEOUT', 600),

    /** Free space that must be left on the disk on top of the estimate, in MB. */
    'free_margin_mb' => (int) env('EXPORT_FREE_MARGIN_MB', 256),

    /** `nice` level of ffmpeg (0 = off), like the other heavy work, so the web and the players win the CPU. */
    'nice' => (int) env('EXPORT_NICE', 10),

    /** Longest side of an embedded cover in pixels (baseline JPEG; many head units reject large ones). */
    'cover_max_px' => 500,

];
