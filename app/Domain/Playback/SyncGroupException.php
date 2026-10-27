<?php

namespace App\Domain\Playback;

/** A sync group can't be made or changed that way (422 `invalid`), or what its leader plays can't be shared. */
class SyncGroupException extends \RuntimeException {}
