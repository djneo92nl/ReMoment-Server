<?php

return [

    /*
    | How many of the most recently played distinct albums count as "recent":
    | kept fully processed by `artwork:prerender`, listed first by
    | GET /api/clients/{api_token}/artwork, and put in the SD card export.
    */
    'recent_albums' => (int) env('ARTWORK_RECENT_ALBUMS', 500),

    /*
    | Most ProcessArtwork jobs `artwork:prerender` queues per run, so a large
    | backlog is worked off over several daily runs instead of flooding the queue.
    */
    'prerender_max_jobs' => (int) env('ARTWORK_PRERENDER_MAX_JOBS', 100),

    /*
    | Cover health (library health report `covers`): the one place the thresholds live. A cover whose shorter side is
    | under `min_cover_px` is "tiny" (the processed 512 px proxy is blown up from it); one whose sides differ by more
    | than `square_tolerance` (a fraction of the longer side) is "not square" (the proxies crop it to a square).
    */
    'min_cover_px' => (int) env('ARTWORK_MIN_COVER_PX', 500),

    'square_tolerance' => (float) env('ARTWORK_SQUARE_TOLERANCE', 0.02),

];
