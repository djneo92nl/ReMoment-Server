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
    | A profile: `mime` and `extension` of the result, and the ffmpeg output arguments. A profile with
    | `'dev' => true` is a rung of the quality ladder on /settings/developer: made and played by hand there,
    | never chosen for a player by itself.
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
        // Lossless, 16-bit / 44.1 kHz stereo: for renderers that decode FLAC only up to CD quality (a Philips Streamium),
        // chosen by Upnp\StreamProfile for a file above the limits of the device.
        'flac-16' => [
            'mime' => 'audio/flac',
            'extension' => 'flac',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-sample_fmt', 's16', '-c:a', 'flac'],
        ],
        // Stereo, 44.1 kHz, 320 kbit/s: for renderers that cannot decode the lossy codec of a file (AAC on a Streamium).
        'mp3-high' => [
            'mime' => 'audio/mpeg',
            'extension' => 'mp3',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '320k'],
        ],
        // Quality ladder of /settings/developer (dev: never picked for a player automatically).
        'flac-16-44' => [
            'group' => 'Lossless',
            'mime' => 'audio/flac',
            'extension' => 'flac',
            'dev' => true,
            'label' => 'FLAC 16-bit / 44.1 kHz',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-sample_fmt', 's16', '-c:a', 'flac'],
        ],
        'mp3-320' => [
            'group' => 'MP3',
            'mime' => 'audio/mpeg',
            'extension' => 'mp3',
            'dev' => true,
            'label' => 'MP3 320 kbit/s',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '320k'],
        ],
        'mp3-192' => [
            'group' => 'MP3',
            'mime' => 'audio/mpeg',
            'extension' => 'mp3',
            'dev' => true,
            'label' => 'MP3 192 kbit/s',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '192k'],
        ],
        'mp3-128' => [
            'group' => 'MP3',
            'mime' => 'audio/mpeg',
            'extension' => 'mp3',
            'dev' => true,
            'label' => 'MP3 128 kbit/s',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '128k'],
        ],
        'mp3-64' => [
            'group' => 'MP3',
            'mime' => 'audio/mpeg',
            'extension' => 'mp3',
            'dev' => true,
            'label' => 'MP3 64 kbit/s',
            'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', '64k'],
        ],
    ] + [
        // Beyond CD quality and other containers, to find what a player decodes at most. Sample rates above the
        // original's are upsampled: they test the player, not the music.
        ...(function (): array {
            $rungs = [];
            $rate = fn (int $hz) => rtrim(rtrim(number_format($hz / 1000, 1), '0'), '.');

            foreach ([48000, 88200, 96000, 176400, 192000] as $hz) {
                $rungs['flac-24-'.$rate($hz)] = [
                    'group' => 'Lossless',
                    'label' => 'FLAC 24-bit / '.$rate($hz).' kHz',
                    'mime' => 'audio/flac',
                    'extension' => 'flac',
                    'dev' => true,
                    'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', (string) $hz, '-sample_fmt', 's32', '-c:a', 'flac'],
                ];
            }

            foreach ([48000, 96000] as $hz) {
                $rungs['flac-16-'.$rate($hz)] = [
                    'group' => 'Lossless',
                    'label' => 'FLAC 16-bit / '.$rate($hz).' kHz',
                    'mime' => 'audio/flac',
                    'extension' => 'flac',
                    'dev' => true,
                    'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', (string) $hz, '-sample_fmt', 's16', '-c:a', 'flac'],
                ];
            }

            foreach ([['wav-16-44', 'WAV 16-bit / 44.1 kHz', 44100, 'pcm_s16le'], ['wav-24-96', 'WAV 24-bit / 96 kHz', 96000, 'pcm_s24le'], ['wav-24-192', 'WAV 24-bit / 192 kHz', 192000, 'pcm_s24le']] as [$name, $label, $hz, $codec]) {
                $rungs[$name] = ['group' => 'Uncompressed', 'label' => $label, 'mime' => 'audio/wav', 'extension' => 'wav', 'dev' => true,
                    'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', (string) $hz, '-c:a', $codec]];
            }

            foreach ([['alac-16-44', 'ALAC 16-bit / 44.1 kHz', 44100, 's16p'], ['alac-24-96', 'ALAC 24-bit / 96 kHz', 96000, 's32p']] as [$name, $label, $hz, $fmt]) {
                $rungs[$name] = ['group' => 'Lossless', 'label' => $label, 'mime' => 'audio/mp4', 'extension' => 'm4a', 'dev' => true,
                    'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', (string) $hz, '-sample_fmt', $fmt, '-c:a', 'alac']];
            }

            foreach ([256, 128] as $kbit) {
                $rungs["aac-{$kbit}"] = ['group' => 'Lossy', 'label' => "AAC {$kbit} kbit/s", 'mime' => 'audio/mp4', 'extension' => 'm4a', 'dev' => true,
                    'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-c:a', 'aac', '-b:a', "{$kbit}k"]];
            }

            $rungs['mp3-v0'] = ['group' => 'MP3', 'label' => 'MP3 V0 (about 245 kbit/s, variable)', 'mime' => 'audio/mpeg', 'extension' => 'mp3', 'dev' => true,
                'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100', '-c:a', 'libmp3lame', '-q:a', '0']];
            $rungs['opus-128'] = ['group' => 'Lossy', 'label' => 'Opus 128 kbit/s', 'mime' => 'audio/ogg', 'extension' => 'opus', 'dev' => true,
                'args' => ['-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '48000', '-c:a', 'libopus', '-b:a', '128k']];

            return $rungs;
        })(),
    ],

];
