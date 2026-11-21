<?php

/*
| Discover (workstream 4, docs/frontend/discover.md): the thresholds of the rediscover shelves, in one place. Everything reads
| the play history and the favourites: no external calls, no new table. A client never decides these, so a change here needs no
| client release.
*/
return [

    /* Items per shelf (the API's `limit` may ask for fewer or up to `max_items`). */
    'items' => (int) env('DISCOVER_ITEMS', 18),

    'max_items' => 60,

    /* Seconds a shelf's selection is kept (plays change all day; a short cache keeps the aggregate queries off every visit). */
    'cache_seconds' => (int) env('DISCOVER_CACHE_SECONDS', 300),

    /* No play for this many days counts as "not played for a long time" (forgotten favourites, "you used to play"). */
    'stale_days' => (int) env('DISCOVER_STALE_DAYS', 180),

    /* "Not played in a year": the last play is at least this old. */
    'long_ago_days' => (int) env('DISCOVER_LONG_AGO_DAYS', 365),

    /*
    | What counts as "you used to play it": an album or artist you listened to on at least this many different days, a track at
    | least this many times (an album played through once is many track plays but one day, so days measure liking better).
    */
    'album_min_days' => 4,

    'artist_min_days' => 6,

    'track_min_plays' => 4,

    /* "On this day": plays within this many days either side of today's date in an earlier year, looking back this many years. */
    'on_this_day_window' => 3,

    'on_this_day_years' => 10,

    /* New releases shelf of the hub and of the API section: how far back (releases still to come are always included). */
    'new_release_days' => 90,

    /* "Mixes well with ..." starts from the most recent play of an analysed track within this many days. */
    'mix_seed_days' => 30,

];
