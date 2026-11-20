<?php

namespace App\Domain\SubDevices;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\Contracts\ReportsActiveSource;
use App\Models\DeviceLink;

/**
 * Whether a link is active: the host has the sub device's input or source selected. Read from the cache the host's
 * listener keeps, so it never waits for a device. `null` is "unknown": the host's driver does not report its selected
 * source (a Philips TV: its HDMI input is not readable), so the link is kept by hand and shows no state.
 */
final class LinkState
{
    public static function active(DeviceLink $link): ?bool
    {
        $host = $link->host;

        if ($host === null || !is_a((string) $host->device_driver, ReportsActiveSource::class, true)) {
            return null;
        }

        if (!in_array(DeviceCache::getState($host->id), [State::Playing, State::Paused], true)) {
            return false;
        }

        return LinkMatcher::matches($link, ActiveInput::get($host->id));
    }
}
