<?php

namespace Djneo92nl\BeoMozart\Enums;

enum RenderingStateValue: string
{
    case Idle = 'idle';
    case Buffering = 'buffering';
    case Started = 'started';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case Ended = 'ended';
    case Error = 'error';
    case Unknown = 'unknown';
}
