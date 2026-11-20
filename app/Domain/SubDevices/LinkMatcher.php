<?php

namespace App\Domain\SubDevices;

use App\Domain\Media\Source;
use App\Models\DeviceLink;
use App\Models\DeviceMeta;

/**
 * Does a host's selected source belong to a link? The link's `via` names the host's source (its id, "hdmi_1"), or the
 * physical input ("HDMI 1") or the source's name; the comparison ignores case and punctuation, so "HDMI 1", "hdmi_1" and
 * "hdmi1" are one input. A `borrow` link with no `via` stands for every source the sub device provides: those carry
 * the sub device's own product id (device meta `converter_jid`, `ase_jid`, `mozart_jid`) in their source id.
 */
final class LinkMatcher
{
    /** Device meta keys that hold the id a product uses in its source ids. */
    public const IDENTITY_KEYS = ['converter_jid', 'ase_jid', 'mozart_jid'];

    public static function matches(DeviceLink $link, ?Source $source): bool
    {
        if ($source === null) {
            return false;
        }

        if ($link->via === '') {
            return $link->kind === DeviceLink::KIND_BORROW && self::providedBy($link, $source);
        }

        $via = self::key($link->via);

        foreach ([$source->jid, $source->input(), $source->name] as $candidate) {
            if ($candidate !== null && $candidate !== '' && self::key($candidate) === $via) {
                return true;
            }
        }

        return false;
    }

    /** Whether the source id contains the sub device's own product id. */
    private static function providedBy(DeviceLink $link, Source $source): bool
    {
        if ($source->jid === null || $source->jid === '') {
            return false;
        }

        $identities = DeviceMeta::where('device_id', $link->device_id)
            ->whereIn('key', self::IDENTITY_KEYS)
            ->pluck('value');

        foreach ($identities as $identity) {
            if ($identity !== '' && str_contains($source->jid, (string) $identity)) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value));
    }
}
