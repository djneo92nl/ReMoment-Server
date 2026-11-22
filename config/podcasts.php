<?php

/*
| Podcasts (workstream 8.2, docs/api/podcasts.md): how often feeds are fetched and how much of each is kept.
*/
return [
    // A feed is fetched at most this often (minutes); a manual refresh may not ask again within `manual_interval`.
    'refresh_interval' => (int) env('PODCASTS_REFRESH_MINUTES', 120),
    'manual_interval' => 5,

    // Feeds fetched per scheduled run, and the gap in seconds between two queued fetches: a long list of feeds trickles in.
    'batch' => 20,
    'spacing_seconds' => 3,

    // A failing feed is asked again after the interval doubled per failure, never later than this many hours.
    'max_backoff_hours' => 24,

    // Newest episodes kept per podcast (a podcast can set its own; 0 keeps everything). Only rows are kept: no audio is downloaded.
    'keep_episodes' => (int) env('PODCASTS_KEEP_EPISODES', 100),

    // Network: a feed larger than this (bytes) is refused.
    'timeout' => 15,
    'max_bytes' => 10 * 1024 * 1024,
    'user_agent' => 'ReMomentServer podcast refresh',
];
