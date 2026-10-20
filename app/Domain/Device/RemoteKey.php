<?php

namespace App\Domain\Device;

/**
 * The remote-control keys a TV or set-top box can be sent, named the same on every platform (a driver maps them to
 * its own key codes). Digits are `DigitsInterface`, standby is `PowerInterface`, input and app selection is
 * `SourcesInterface`: this is what is left of a remote.
 */
enum RemoteKey: string
{
    case Up = 'up';
    case Down = 'down';
    case Left = 'left';
    case Right = 'right';
    case Ok = 'ok';
    case Back = 'back';
    case Home = 'home';
    case Menu = 'menu';
    case Info = 'info';
    case Guide = 'guide';
    case Exit = 'exit';
    case Play = 'play';
    case Pause = 'pause';
    case Stop = 'stop';
    case Next = 'next';
    case Previous = 'previous';
    case FastForward = 'fast_forward';
    case Rewind = 'rewind';
    case VolumeUp = 'volume_up';
    case VolumeDown = 'volume_down';
    case Mute = 'mute';
    case ChannelUp = 'channel_up';
    case ChannelDown = 'channel_down';
    case Red = 'red';
    case Green = 'green';
    case Yellow = 'yellow';
    case Blue = 'blue';
}
