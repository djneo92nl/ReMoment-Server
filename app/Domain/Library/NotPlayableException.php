<?php

namespace App\Domain\Library;

/** Neither DLNA nor Spotify can play this album/artist/track on the device (422 `not_playable`). */
class NotPlayableException extends \RuntimeException {}
