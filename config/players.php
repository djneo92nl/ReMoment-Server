<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Player housekeeping (docs/api/players.md, "Housekeeping")
    |--------------------------------------------------------------------------
    |
    | Every reinstall or unsigned build of a ReMoment Player registers a new device. A player that has not reported
    | for this many days is hidden (never deleted) by the daily `players:hide-stale`; it comes back on its own
    | when it reports again. 0 switches the housekeeping off.
    |
    */

    'hide_after_days' => (int) env('PLAYERS_HIDE_AFTER_DAYS', 30),

];
