<?php

namespace App\Domain\SubDevices;

/** A link that cannot exist: the message says why, in words the card and the API show as they are. */
class InvalidDeviceLink extends \InvalidArgumentException {}
