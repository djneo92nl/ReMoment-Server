<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ripping a CD on a ReMoment Node (node role `ripper`)
    |--------------------------------------------------------------------------
    |
    | See docs/architecture/node-ripper.md. The drive and the cyanrip binary are the node's own configuration.
    |
    */

    // How long the answer of a disc check (`ripper.status`) is trusted before a rip needs a new check.
    'disc_fresh_seconds' => (int) env('RIPPER_DISC_FRESH_SECONDS', 900),

    // A failed, lost or expired rip keeps its `rip.failed` alert for this long (or until a later rip on the node succeeds).
    'alert_hours' => (int) env('RIPPER_ALERT_HOURS', 24),

];
