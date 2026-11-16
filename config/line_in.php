<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Line-in as a stream source (node role `line-in`)
    |--------------------------------------------------------------------------
    |
    | A ReMoment Node with the line-in role captures an ALSA input with ffmpeg and pushes MP3 to this server's Icecast
    | (the same container as the radio relay and the Spotify stream; Icecast settings: config/radio_relay.php).
    | Latency is 2 to 5 s. See docs/architecture/line-in.md.
    |
    */

    // A line-in nobody listens to for this long is stopped (the node's ffmpeg ends).
    'idle_seconds' => (int) env('LINE_IN_IDLE_SECONDS', 120),

    // How long a play request waits for the node to bring the mount up.
    'live_wait_seconds' => (int) env('LINE_IN_LIVE_WAIT_SECONDS', 20),

];
