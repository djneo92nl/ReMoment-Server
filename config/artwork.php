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

];
