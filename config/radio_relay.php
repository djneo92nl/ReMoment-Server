<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Radio relay (station -> ffmpeg -> Icecast -> renderer)
    |--------------------------------------------------------------------------
    |
    | A station a renderer cannot play itself (HTTPS, AAC, a playlist, a renderer without name resolution) is
    | converted by the server to MP3 and served from the local Icecast. The `radio-relay` compose profile runs
    | Icecast; `php artisan radio:relay` (supervisor program `radio-relay`) runs one ffmpeg per wanted station.
    | See docs/architecture/radio-relay.md.
    |
    */

    'enabled' => (bool) env('RADIO_RELAY_ENABLED', false),

    // kbit/s of the MP3 (CBR, 44.1 kHz stereo).
    'bitrate' => (int) env('RADIO_RELAY_BITRATE', 128),

    // Relays running at once. Over the limit the longest idle one stops; with none idle a new one waits.
    'max' => (int) env('RADIO_RELAY_MAX', 4),

    // A relay nobody asked for during this long, and nobody listens to, is stopped.
    'idle_seconds' => (int) env('RADIO_RELAY_IDLE_SECONDS', 60),

    // How long a play request waits for the mount to appear.
    'live_wait_seconds' => (int) env('RADIO_RELAY_LIVE_WAIT_SECONDS', 15),

    // Sent to the station; some refuse an empty or unknown agent.
    'user_agent' => env('RADIO_RELAY_USER_AGENT', 'ReMoment/1.0'),

    'icecast' => [
        // Where this server talks to Icecast (status, titles, and the ffmpeg source connection).
        'internal_url' => env('ICECAST_INTERNAL_URL', 'http://127.0.0.1:8000'),
        // The port renderers reach it on, on the host of STREAM_BASE_URL / the request.
        'port' => (int) env('ICECAST_PORT', 8000),
        'source_password' => env('ICECAST_SOURCE_PASSWORD', 'remoment'),
        'admin_user' => env('ICECAST_ADMIN_USER', 'admin'),
        'admin_password' => env('ICECAST_ADMIN_PASSWORD', 'remoment'),
    ],

];
