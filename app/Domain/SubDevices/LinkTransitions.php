<?php

namespace App\Domain\SubDevices;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Models\Device;
use App\Models\DeviceLink;
use Illuminate\Support\Facades\Cache;

/**
 * Sees a link become active or end. Every event of a host (a source reported, standby) asks `observe()`, which compares
 * what `LinkState` says now with what it said last time, kept per link in the cache. It does not matter who changed the
 * input: a remote, the TV's own menu and a call of ours all arrive as the host's source notification. The first time a
 * link is seen it counts as inactive before, so an input that is already selected when the link is first observed counts
 * as just activated (the actions are meant to be repeatable: power on, a source).
 */
final class LinkTransitions
{
    public const ACTIVATE = 'activate';

    public const DEACTIVATE = 'deactivate';

    /**
     * Records the state of every link of the host and returns the ones that changed, as `[link, ACTIVATE|DEACTIVATE]`.
     * A host that is unreachable, or whose driver does not report its source, changes nothing: the state is unknown, not
     * inactive.
     *
     * @return list<array{0: DeviceLink, 1: string}>
     */
    public static function observe(Device $host): array
    {
        if (DeviceCache::getState($host->id) === State::Unreachable) {
            return [];
        }

        $changes = [];

        foreach (DeviceLinks::subLinks($host) as $link) {
            $link->setRelation('host', $host);
            $active = LinkState::active($link);

            if ($active === null) {
                continue;
            }

            $was = (bool) Cache::get(self::key($link), false);

            if ($active === $was) {
                continue;
            }

            Cache::forever(self::key($link), $active);
            $changes[] = [$link, $active ? self::ACTIVATE : self::DEACTIVATE];
        }

        return $changes;
    }

    /** What `observe()` recorded for the link: whether it was active the last time a host event was seen. */
    public static function wasActive(DeviceLink $link): bool
    {
        return (bool) Cache::get(self::key($link), false);
    }

    private static function key(DeviceLink $link): string
    {
        return "device_link:{$link->id}:active";
    }
}
