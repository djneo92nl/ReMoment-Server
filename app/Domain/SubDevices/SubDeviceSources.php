<?php

namespace App\Domain\SubDevices;

use App\Domain\Device\AvailableSource;
use App\Models\Device;
use App\Models\DeviceLink;

/**
 * The host's source list with its links in it: every link of a host is an entry (`AvailableSource` with `input` = the
 * link's `via`, `active` while the link is active, `sub_device` naming the device behind it). A source the host lists
 * itself and the link names (the V1's `hdmi_1`) is marked, not duplicated; a link that names no listed source (an input
 * the host does not list, a Philips TV's HDMI) gets an entry of its own, activated by its `via` as a source id. A `borrow`
 * link without `via` has no entry of its own: the sub device's sources are listed as the host's borrowed ones.
 *
 * Done on the way out only (the API answer): the stored `device_sources` stay what the driver reported.
 */
final class SubDeviceSources
{
    /** The id of the first entry that is no source of the host, so it sorts after them. */
    public const SORT_AFTER = 1000;

    /**
     * @param  AvailableSource[]  $sources  what the host's driver listed
     * @return AvailableSource[]
     */
    public static function decorate(Device $host, array $sources): array
    {
        if (!LinkIndex::isHost($host->id)) {
            return $sources;
        }

        foreach (DeviceLinks::subLinks($host) as $link) {
            $link->setRelation('host', $host);
            $active = LinkState::active($link);
            $sub = ['id' => $link->device->id, 'name' => (string) $link->device->device_name];
            $matched = false;

            foreach ($sources as $source) {
                if (LinkMatcher::matchesListed($link, $source)) {
                    $matched = true;
                    $source->subDevice ??= $sub;
                    $source->active ??= $active;
                }
            }

            if (!$matched && $link->via !== '') {
                $sources[] = self::entry($link, $sub, $active);
            }
        }

        return $sources;
    }

    /** @param  array{id: int, name: string}  $sub */
    private static function entry(DeviceLink $link, array $sub, ?bool $active): AvailableSource
    {
        return new AvailableSource(
            sourceId: $link->via,
            friendlyName: $sub['name'],
            sourceType: strtoupper($link->kind),
            category: 'link',
            inUse: true,
            borrowed: false,
            providerJid: null,
            providerName: null,
            input: $link->via,
            active: $active,
            subDevice: $sub,
        );
    }
}
