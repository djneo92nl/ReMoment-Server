<?php

namespace App\Domain\Device;

enum State: string
{
    case Playing = 'playing';
    case Standby = 'standby';
    case Unreachable = 'unreachable';
    case Paused = 'paused';

}
