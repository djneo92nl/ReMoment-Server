<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audio proxies: smaller copies of the server's own files
    |--------------------------------------------------------------------------
    |
    | A player that cannot decode the original (FLAC on a Palm, 320 kbit/s stereo on a Pocket PC) is sent
    | a proxy instead: `GET /stream/{track}?profile=mp3-lo`. Proxies are made on demand by the
    | `TranscodeProxy` job, kept on the private `local` disk (`proxies/`) and pruned oldest-first when
    | they exceed `max_mb`. Nothing is transcoded ahead of time. See docs/architecture/own-player.md.
    |
    | A profile: `mime` and `extension` of the result, and the ffmpeg output arguments.
    |
    */

    'enabled' => (bool) env('PROXIES_ENABLED', true),

    'queue' => env('PROXIES_QUEUE', 'heavy'),

    /** Seconds one transcode may take. */
    'timeout' => (int) env('PROXIES_TIMEOUT', 600),

    /** Disk the proxies are kept on, and the folder on it. */
    'disk' => env('PROXIES_DISK', 'local'),

    'directory' => 'proxies',

    /** Size the proxies may take together; the least recently made ones go first. 0 = no limit. */
    'max_mb' => (int) env('PROXIES_MAX_MB', 2048),

    'profiles' => [
        // Mono, 22 kHz, 48 kbit/s: for handhelds and microcontrollers with a small MP3 decoder.
        'mp3-lo' => [
            'mime' => 'audio/mpeg',
            'extension' => 'mp3',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '1', '-ar', '22050', '-c:a', 'libmp3lame', '-b:a', '48k'],
        ],
        // Stereo, 44.1 kHz, 128 kbit/s: for slow links and older players.
        'mp3-mid' => [
            'mime' => 'audio/mpeg',
            'extension' => 'mp3',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '128k'],
        ],
    ],

];
