<?php

/*
| Resume position (workstream 8.3, docs/api/playback-positions.md): which tracks keep a bookmark and how it is kept.
*/
return [
    // A track at least this long (minutes) keeps a bookmark; shorter ones only when their genre says audiobook or podcast.
    'min_minutes' => (int) env('RESUME_MIN_MINUTES', 20),

    // Genres (lower case, canonical or tag) that make a track bookmarkable whatever its length.
    'genres' => ['audiobook', 'audiobooks', 'audio book', 'podcast', 'podcasts', 'spoken word', 'spoken & audio', 'hörbuch', 'hoerbuch', 'luisterboek'],

    // Nothing is bookmarked in the first seconds of a track, so a start from the beginning cannot overwrite a bookmark before it is restored.
    'min_seconds' => 10,

    // Within this many seconds of the end the item counts as finished and its bookmark is cleared.
    'tail_seconds' => 30,

    // At most one database write per device in this many seconds while progress arrives.
    'save_interval' => 10,
];
