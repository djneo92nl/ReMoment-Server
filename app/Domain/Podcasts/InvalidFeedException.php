<?php

namespace App\Domain\Podcasts;

/** The address is not a podcast feed we can read: not http(s), unreachable, too large, or not RSS. */
class InvalidFeedException extends \RuntimeException {}
