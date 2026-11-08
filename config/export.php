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
    ],

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
