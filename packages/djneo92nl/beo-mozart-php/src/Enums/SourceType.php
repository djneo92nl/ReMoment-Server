<?php

namespace Djneo92nl\BeoMozart\Enums;

/**
 * NOTE: these values come from the free-text `description:` block on
 * `SourceTypeEnum.value` in the Mozart OpenAPI spec, not a real YAML `enum:`
 * (unlike PlaybackCommand/RenderingStateValue). Always resolve via
 * SourceType::tryFrom() and fall back to the raw string when unrecognized —
 * never assume this list is exhaustive or verified against real hardware.
 */
enum SourceType: string
{
    case Beolink = 'beolink';
    case Bluetooth = 'bluetooth';
    case Dlna = 'dlna';
    case QPlay = 'qplay';
    case AirPlay = 'airPlay';
    case LineIn = 'lineIn';
    case ChromeCast = 'chromeCast';
    case UriStreamer = 'uriStreamer';
    case NetRadio = 'netRadio';
    case Local = 'local';
    case Generator = 'generator';
    case Spotify = 'spotify';
    case Spdif = 'spdif';
    case Pl = 'pl';
    case Wpl = 'wpl';
    case Tv = 'tv';
    case Deezer = 'deezer';
    case ClassicsAdapter = 'classicsAdapter';
    case UsbIn = 'usbIn';
    case Tidal = 'tidal';
    case TidalConnect = 'tidalConnect';
    case Unknown = 'unknown';
}
