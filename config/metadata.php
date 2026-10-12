<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User-Agent
    |--------------------------------------------------------------------------
    |
    | Sent to every metadata service (MusicBrainz asks for a contact in it).
    |
    */

    'user_agent' => env('METADATA_USER_AGENT', 'ReMoment/1.0 (remko@pionect.nl)'),

    /*
    |--------------------------------------------------------------------------
    | TheAudioDB
    |--------------------------------------------------------------------------
    |
    | Artist images (photo, fanart, logo, banner), moods, styles and descriptions.
    | "123" is TheAudioDB's public test key (about 30 requests a minute); a
    | supporter key from theaudiodb.com lifts the limits. The old key "2"
    | now answers 404 "Not found" for every artist, so nothing was ever found.
    |
    */

    'audiodb_key' => env('AUDIODB_API_KEY', '123'),

    /*
    |--------------------------------------------------------------------------
    | Discogs
    |--------------------------------------------------------------------------
    |
    | Release credits, formats and styles. A personal access token from
    | discogs.com/settings/developers; without one Discogs is skipped.
    |
    */

    'discogs_token' => env('DISCOGS_TOKEN'),

];
