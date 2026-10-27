<?php

return [

    /*
    |--------------------------------------------------------------------------
    | BeoLink stream (B&O NetworkLink listener -> ffmpeg -> Icecast)
    |--------------------------------------------------------------------------
    |
    | The optional `beolink-stream` compose profile runs a receiver that joins a Bang & Olufsen source's multiroom
    | experience (B&O Radio, a Beoplay M3/M5 line-in, ...) and serves its audio as an MP3 stream, so a speaker
    | outside the B&O ecosystem, a browser or the iOS app can play along. See docs/architecture/beolink-stream.md.
    |
    */

    'enabled' => (bool) env('BEOLINK_ENABLED', false),

    // The name the receiver shows as a B&O product (in the B&O app, as a listener to add to a source).
    'name' => env('BEOLINK_NAME', 'ReMoment Stream'),

    // The receiver's Mozart REST API (beo's facade); ReMoment joins and leaves a source through it.
    'api_url' => rtrim((string) env('BEOLINK_API_URL', 'http://127.0.0.1:8081'), '/'),

    'icecast' => [
        // How the speakers reach Icecast: the host of STREAM_BASE_URL / the request, plus this port (shared with Spotify).
        'port' => (int) env('ICECAST_PORT', 8000),
        'mount' => env('ICECAST_BEOLINK_MOUNT', 'beolink.mp3'),
    ],

];
