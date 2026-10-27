<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Moving playback to another device
    |--------------------------------------------------------------------------
    |
    | After starting the queue on the target, how long to wait for it to report
    | `playing` before seeking to the position it was moved from. See
    | docs/api/playback-move.md.
    */
    'move_wait_seconds' => (int) env('PLAYBACK_MOVE_WAIT_SECONDS', 10),

];
