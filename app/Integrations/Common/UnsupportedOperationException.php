<?php

namespace App\Integrations\Common;

/**
 * Thrown by a driver for one operation of a capability it otherwise
 * supports (e.g. a device that can be put in standby but not woken up).
 * The REST API answers it with 422 "unsupported".
 */
class UnsupportedOperationException extends \RuntimeException {}
