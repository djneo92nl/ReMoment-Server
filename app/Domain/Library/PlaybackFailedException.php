<?php

namespace App\Domain\Library;

/** The device or Spotify refused or didn't respond (502 `driver_error`). */
class PlaybackFailedException extends \RuntimeException {}
