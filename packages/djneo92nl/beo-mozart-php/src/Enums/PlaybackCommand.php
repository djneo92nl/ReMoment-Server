<?php

namespace Djneo92nl\BeoMozart\Enums;

enum PlaybackCommand: string
{
    case Play = 'play';
    case Pause = 'pause';
    case Stop = 'stop';
    case Skip = 'skip';
    case Prev = 'prev';
}
