<?php

namespace App\Domain\SubDevices;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Jobs\FollowStandby;
use App\Jobs\FollowSubDevice;
use App\Models\Device;
use App\Models\DeviceLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The follow rule: the other direction of a link. When the sub device starts playing, its host selects the link, which is
 * what makes a Chromecast usable behind a TV that has no CEC and cannot be woken by the dongle: the Chromecast (a device
 * with the Cast driver) reports a cast session, and the rule activates `hdmi_1` on the Beoplay V1, which wakes it and
 * switches it to that input. Setting (link `meta`): `follow` `off` | `from_standby` (default) | `always`, and
 * `follow_off_after` minutes or null.
 *
 * - Trigger: the sub device's state going to Playing from not playing (`TrackSubDeviceLinks`), a short debounce later
 *   (`follow_debounce_seconds`: apps start and stop several times in a row), when the session is still playing. The
 *   Chromecast's idle backdrop is never a session (its listener reports it as standby), and `isActiveInput` is not used.
 * - Guard: `from_standby` switches only a host in standby (or one already on the link's input: nothing to do);
 *   `always` also takes the screen from another input; `off` disables it. A host on another input is never switched by
 *   default, so a cast does not interrupt the set-top box. A host that is unreachable is left alone.
 * - Turning off: with `follow_off_after` set, the host goes to standby that long after the sub device stops, but only if
 *   this rule woke it (`meta.state.woke_host`), never a TV someone turned on themselves. The mark is cleared as soon as the
 *   link ends (the host went to another input or to standby), and a host that was already on, switched by `always`, is not marked.
 *
 * Only `input` and `source` links follow: the rule activates the link's `via` on the host. Nothing here tells our own
 * switch from someone else's: our own produces a link that becomes active, which the host's events treat like any other.
 * **Unverified on hardware** (how long the V1 takes to show the input, what a dongle in a standby TV reports).
 */
final class FollowRule
{
    public const SWITCHED = 'switched';

    /** A session of the sub device started: schedule the check after the debounce, once per link and window. */
    public static function sessionStarted(Device $sub): void
    {
        foreach (self::followingLinks($sub) as $link) {
            if (LinkMeta::follow($link->meta) === 'off') {
                continue;
            }

            $debounce = max(0, (int) config('sub-devices.follow_debounce_seconds'));

            if (Cache::add("device_link:{$link->id}:follow", true, max(1, $debounce))) {
                FollowSubDevice::dispatch($link->id)->delay(now()->addSeconds($debounce));
            }
        }
    }

    /** The session of the sub device ended: schedule the host's standby for the links that ask for it. */
    public static function sessionStopped(Device $sub): void
    {
        foreach (self::followingLinks($sub) as $link) {
            $after = $link->meta['follow_off_after'] ?? null;

            if ($after === null || LinkMeta::follow($link->meta) === 'off' || !self::wokeHost($link)) {
                continue;
            }

            // The stop is the job's identity: a later stop supersedes it, a restart is seen by the sub device's state.
            $stoppedAt = now()->getTimestamp();
            Cache::put(self::stoppedKey($link), $stoppedAt, now()->addMinutes($after + 5));
            FollowStandby::dispatch($link->id, $stoppedAt)->delay(now()->addMinutes($after));
        }
    }

    /**
     * After the debounce: switch the host to the link when the guard allows it.
     *
     * @return string `switched`, or why not: off, sub_idle, host_unreachable, already_on_input, host_busy, cannot_switch
     */
    public static function follow(DeviceLink $link): string
    {
        $link->loadMissing('device', 'host');
        $sub = $link->device;
        $host = $link->host;
        $mode = LinkMeta::follow($link->meta);

        if ($sub === null || $host === null || $mode === 'off') {
            return 'off';
        }

        if (DeviceCache::getState($sub->id) !== State::Playing) {
            return 'sub_idle';
        }

        $hostState = DeviceCache::getState($host->id);

        if ($hostState === State::Unreachable) {
            return 'host_unreachable';
        }

        if (LinkState::active($link->setRelation('host', $host)) === true) {
            return 'already_on_input';
        }

        $wasStandby = $hostState === State::Standby;

        if (!$wasStandby && $mode === 'from_standby') {
            return 'host_busy';
        }

        $driver = $host->driver;

        if (!($driver instanceof SourceActivationInterface) || $link->via === '') {
            return 'cannot_switch';
        }

        // Before the call: the wake it causes must not meet the default-input-on-wake.
        Cache::put(self::switchedKey($host->id), true, max(1, (int) config('sub-devices.follow_switch_guard_seconds')));
        $driver->activateSource($link->via);

        self::markWoke($link, $wasStandby);

        return self::SWITCHED;
    }

    /**
     * The delayed standby: only if this rule woke the host, the link is still active (the host is still on the sub device's
     * input), the sub device is still stopped and this is the latest stop.
     *
     * @return string `standby`, or why not
     */
    public static function standbyAfterStop(DeviceLink $link, int $stoppedAt): string
    {
        $link->loadMissing('device', 'host');
        $sub = $link->device;
        $host = $link->host;

        if ($sub === null || $host === null || !self::wokeHost($link) || LinkMeta::follow($link->meta) === 'off') {
            return 'not_woken_by_rule';
        }

        if (Cache::get(self::stoppedKey($link)) !== $stoppedAt) {
            return 'superseded';
        }

        if (in_array(DeviceCache::getState($sub->id), [State::Playing, State::Paused], true)) {
            return 'sub_active';
        }

        if (LinkState::active($link->setRelation('host', $host)) !== true) {
            self::forgetWoke($link);

            return 'host_moved';
        }

        $driver = $host->driver;

        if (!($driver instanceof PowerInterface)) {
            return 'cannot_standby';
        }

        self::forgetWoke($link);
        $driver->standby();

        return 'standby';
    }

    /** Whether the follow rule woke the link's host and has not yet turned it off or lost it. */
    public static function wokeHost(DeviceLink $link): bool
    {
        return (bool) ($link->fresh()?->meta['state']['woke_host'] ?? false);
    }

    /** The link ended (the host went to another input or to standby): the host is nobody's to turn off any more. */
    public static function forgetWoke(DeviceLink $link): void
    {
        $fresh = $link->fresh();

        if ($fresh === null || !($fresh->meta['state']['woke_host'] ?? false)) {
            return;
        }

        $meta = $fresh->meta;
        unset($meta['state']['woke_host'], $meta['state']['woke_at']);

        if (($meta['state'] ?? []) === []) {
            unset($meta['state']);
        }

        self::save($fresh, $meta);
    }

    /** Whether the rule switched this host a moment ago (SwitchToDefaultSource leaves it alone). */
    public static function recentlySwitched(int $hostId): bool
    {
        return Cache::has(self::switchedKey($hostId));
    }

    private static function markWoke(DeviceLink $link, bool $woke): void
    {
        $fresh = $link->fresh();

        if ($fresh === null) {
            return;
        }

        $meta = $fresh->meta ?? [];

        if ($woke) {
            $meta['state'] = ['woke_host' => true, 'woke_at' => now()->toIso8601String()] + ($meta['state'] ?? []);
        } else {
            unset($meta['state']['woke_host'], $meta['state']['woke_at']);
            if (($meta['state'] ?? []) === []) {
                unset($meta['state']);
            }
        }

        self::save($fresh, $meta);
    }

    /** @param  array<string, mixed>  $meta */
    private static function save(DeviceLink $link, array $meta): void
    {
        try {
            $link->meta = $meta === [] ? null : $meta;
            $link->save();
        } catch (\Throwable $e) {
            Log::warning("Follow rule: could not save the state of link {$link->id}: {$e->getMessage()}");
        }
    }

    /** @return \Illuminate\Support\Collection<int, DeviceLink> the links where $sub is connected to a host by an input or source */
    private static function followingLinks(Device $sub)
    {
        return DeviceLinks::hostLinks($sub)->filter(fn (DeviceLink $link) => in_array($link->kind, [DeviceLink::KIND_INPUT, DeviceLink::KIND_SOURCE], true) && $link->via !== '');
    }

    private static function switchedKey(int $hostId): string
    {
        return "device:{$hostId}:follow_switched";
    }

    private static function stoppedKey(DeviceLink $link): string
    {
        return "device_link:{$link->id}:stopped_at";
    }
}
