<?php

namespace Djneo92nl\BeoMozart\Enums;

/**
 * NOTE: these values come from the free-text `description:` block on
 * `SourceTypeEnum.value` in the Mozart OpenAPI spec, not a real YAML `enum:`
 * (unlike PlaybackCommand/RenderingStateValue). Always resolve via
 * SourceType::tryFrom() and fall back to the raw string when unrecognized —
 * never assume this list is exhaustive or verified against real hardware.
 *
 * Deliberately NOT shared with the ASE driver's source types (e.g. "HDMI",
 * "TV" from the older BeoZone protocol) — ASE doesn't use an enum for this
 * today, and the two protocol generations' literal vocabularies aren't
 * confirmed to match despite overlapping concepts. Revisit once ASE is
 * actually extracted into its own package (see
 * docs/architecture/plugin-architecture.md) and real values from both can
 * be compared side by side.
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
