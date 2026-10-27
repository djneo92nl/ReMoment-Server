<?php

namespace App\Domain\Playback;

/** What a device plays can't be moved to another device: nothing playing, or a local input (422 `not_movable`). */
class NotMovableException extends \RuntimeException {}
