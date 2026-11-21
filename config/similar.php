<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Similar tracks (workstream 4, docs/architecture/similar-tracks.md)
    |--------------------------------------------------------------------------
    |
    | The sound embeddings of the own files live in the `file_embeddings` table. They are searched by an exact
    | scan in PHP by default, which needs nothing and is fine for a few thousand tracks. `sqlite-vec` keeps a derived
    | copy in a separate SQLite file (`similar:index`) and is the faster path for tens of thousands; it is optional,
    | needs the vec0 library (SIMILAR_VEC_EXTENSION, an absolute path) and PHP 8.4's Pdo\Sqlite, and the server
    | falls back to the scan whenever it is missing, not built or out of date.
    */

    'engine' => env('SIMILAR_ENGINE', 'php'), // php | sqlite-vec

    'vec_extension' => env('SIMILAR_VEC_EXTENSION'),

    'vec_path' => env('SIMILAR_VEC_PATH', storage_path('app/vectors.sqlite')),

    /** The most neighbours one query keeps (and so the deepest page of the list). */
    'max_results' => 200,

    /** Tracks one "similar tracks radio" queues, the seed included. */
    'radio_length' => 50,

    /** How long a result is reused (seconds), unless an embedding was written meanwhile. */
    'cache_seconds' => 300,

    /** sqlite-vec returns the nearest rows of the whole library; this many times the wanted count are asked for, so a scope filter still leaves enough. */
    'vec_overfetch' => 4,
];
