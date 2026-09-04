<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | Last.fm's public REST API requires an API key (no OAuth/secret needed
    | for read-only endpoints like artist.getInfo). Get one at
    | https://www.last.fm/api/account/create
    |
    */

    'api_key' => env('LASTFM_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Shared Secret
    |--------------------------------------------------------------------------
    |
    | Needed only for authenticated calls (session auth, scrobbling). Found
    | on the same API app page as the key.
    |
    */

    'shared_secret' => env('LASTFM_SHARED_SECRET'),

];
