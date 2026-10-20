<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Spotify stream (librespot -> ffmpeg -> Icecast)
    |--------------------------------------------------------------------------
    |
    | The optional `spotify-stream` compose profile runs a Spotify Connect receiver whose audio is
    | served as an MP3 stream, so a speaker without Spotify can play it. See docs/architecture/spotify-stream.md.
    |
    */

    'enabled' => (bool) env('LIBRESPOT_ENABLED', false),

    // The name the receiver shows in Spotify's device list (map it at /settings/spotify-connect).
    'name' => env('LIBRESPOT_NAME', 'ReMoment Stream'),

    'icecast' => [
        // How the speakers reach Icecast: the host of STREAM_BASE_URL / the request, plus this port.
        'port' => (int) env('ICECAST_PORT', 8000),
        'mount' => env('ICECAST_MOUNT', 'spotify.mp3'),
        // Where this server talks to Icecast itself (metadata updates).
        'internal_url' => env('ICECAST_INTERNAL_URL', 'http://127.0.0.1:8000'),
        'admin_user' => env('ICECAST_ADMIN_USER', 'admin'),
        'admin_password' => env('ICECAST_ADMIN_PASSWORD', 'remoment'),
    ],

    // Shared secret of the container's --onevent hook (sent as the X-Librespot-Secret header).
    'event_secret' => env('LIBRESPOT_EVENT_SECRET', 'remoment'),

];
