<?php

namespace App\Domain\Device;

/** Repeat setting as exposed by the REST API and the MQTT `/modes` topic. */
enum RepeatMode: string
{
    case Off = 'off';
    case All = 'all';
    case One = 'one';
}
