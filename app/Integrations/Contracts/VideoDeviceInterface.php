<?php

namespace App\Integrations\Contracts;

/**
 * Marker for a driver of a TV (or set-top box): its device page gets the TV layout (inputs and apps as grids,
 * sleep timer and number keys). It adds no method; what the TV can do is still told by the other contracts.
 */
interface VideoDeviceInterface {}
