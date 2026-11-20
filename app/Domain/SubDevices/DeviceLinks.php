<?php

namespace App\Domain\SubDevices;

use App\Domain\Device\DeviceModels;
use App\Models\Device;
use App\Models\DeviceLink;
use Illuminate\Support\Collection;

/**
 * Sub devices (workstream 2a, docs/architecture/sub-devices.md): the one place that creates, checks and reads
 * `device_links`. A link says that device A (the sub device) is connected to device B (its host), and how the host
 * selects it. Both stay full devices; nothing here changes how either is controlled by itself.
 */
final class DeviceLinks
{
    /**
     * The rules every saved link keeps, whoever saves it (card, API, discovery): a known kind, two different devices,
     * a `via` where the kind needs one, and one level only (a host is no sub device, a sub device no host).
     *
     * @throws InvalidDeviceLink
     */
    public static function validate(DeviceLink $link): void
    {
        if (!in_array($link->kind, DeviceLink::KINDS, true)) {
            throw new InvalidDeviceLink("Unknown link kind '{$link->kind}' (borrow, input or source).");
        }

        if ((int) $link->device_id === (int) $link->host_device_id) {
            throw new InvalidDeviceLink('A device cannot be connected to itself.');
        }

        $link->via = trim((string) $link->via);

        if ($link->via === '' && $link->kind !== DeviceLink::KIND_BORROW) {
            throw new InvalidDeviceLink('A link of kind '.$link->kind.' needs the host input or source it is connected to.');
        }

        if (self::isHost((int) $link->device_id, $link->exists ? $link->id : null)) {
            throw new InvalidDeviceLink('That device is a host itself: sub devices go one level deep.');
        }

        if (self::isSub((int) $link->host_device_id, $link->exists ? $link->id : null)) {
            throw new InvalidDeviceLink('The host is a sub device itself: sub devices go one level deep.');
        }
    }

    /** @param  array<string, mixed>  $meta */
    public static function add(Device $sub, Device $host, string $kind, string $via = '', array $meta = []): DeviceLink
    {
        return DeviceLink::create([
            'device_id' => $sub->id,
            'host_device_id' => $host->id,
            'kind' => $kind,
            'via' => $via,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    public static function isHost(int $deviceId, ?int $exceptLinkId = null): bool
    {
        return DeviceLink::where('host_device_id', $deviceId)
            ->when($exceptLinkId !== null, fn ($query) => $query->where('id', '!=', $exceptLinkId))
            ->exists();
    }

    public static function isSub(int $deviceId, ?int $exceptLinkId = null): bool
    {
        return DeviceLink::where('device_id', $deviceId)
            ->when($exceptLinkId !== null, fn ($query) => $query->where('id', '!=', $exceptLinkId))
            ->exists();
    }

    /**
     * The links of the sub devices connected to $host, oldest first, with the sub device loaded.
     *
     * @return Collection<int, DeviceLink>
     */
    public static function subLinks(Device $host): Collection
    {
        return DeviceLink::where('host_device_id', $host->id)->with('device')->orderBy('id')->get()
            ->filter(fn (DeviceLink $link) => $link->device !== null)->values();
    }

    /**
     * The links of the hosts $sub is connected to, oldest first, with the host loaded.
     *
     * @return Collection<int, DeviceLink>
     */
    public static function hostLinks(Device $sub): Collection
    {
        return DeviceLink::where('device_id', $sub->id)->with('host')->orderBy('id')->get()
            ->filter(fn (DeviceLink $link) => $link->host !== null)->values();
    }

    /**
     * The sub devices of $host whose link is active now, as links (the sub device loaded). A link with an unknown
     * state (`LinkState::active() === null`) is not counted: nothing is routed on a guess.
     *
     * @return Collection<int, DeviceLink>
     */
    public static function activeSubLinks(Device $host): Collection
    {
        return self::subLinks($host)->filter(function (DeviceLink $link) use ($host) {
            $link->setRelation('host', $host);

            return LinkState::active($link) === true;
        })->values();
    }

    /** The host link that is active for $sub, else null: where a command to the sub device would also be heard. */
    public static function activeHostLink(Device $sub): ?DeviceLink
    {
        return self::hostLinks($sub)->first(function (DeviceLink $link) use ($sub) {
            $link->setRelation('device', $sub);

            return LinkState::active($link) === true;
        });
    }

    /**
     * What `GET /api/devices/{id}` says about the relation: `host` (`{id, name, via}` of the host that has this device
     * selected, else the oldest host; null for no host) and `sub_devices` (`[{id, name, via, active}]`). `via` is null
     * for a link with none; `active` is null while unknown.
     *
     * @return array{host: ?array, sub_devices: array<int, array>}
     */
    public static function describe(Device $device): array
    {
        $hostLinks = self::hostLinks($device);
        $hostLink = $hostLinks->first(fn (DeviceLink $link) => LinkState::active($link->setRelation('device', $device)) === true) ?? $hostLinks->first();

        return [
            'host' => $hostLink === null ? null : [
                'id' => $hostLink->host->id,
                'name' => $hostLink->host->device_name,
                'via' => self::via($hostLink),
            ],
            'sub_devices' => self::subLinks($device)->map(fn (DeviceLink $link) => [
                'id' => $link->device->id,
                'name' => $link->device->device_name,
                'via' => self::via($link),
                'active' => LinkState::active($link->setRelation('host', $device)),
            ])->all(),
        ];
    }

    /** One link as the admin API and the card show it. */
    public static function toArray(DeviceLink $link): array
    {
        return [
            'id' => $link->id,
            'device_id' => $link->device_id,
            'device_name' => $link->device?->device_name,
            'host_device_id' => $link->host_device_id,
            'host_name' => $link->host?->device_name,
            'kind' => $link->kind,
            'via' => self::via($link),
            'meta' => $link->meta ?? (object) [],
            'active' => LinkState::active($link),
        ];
    }

    private static function via(DeviceLink $link): ?string
    {
        return $link->via === '' ? null : $link->via;
    }

    /**
     * A device list as the grid shows it: a sub device that cannot be controlled by itself (`standalone` false on its
     * model) is left out, its host's card carries it. A sub device none of whose hosts is in the list keeps its card, so
     * a hidden host never makes a device unreachable from the grid.
     *
     * @param  Collection<int, Device>  $devices
     * @return Collection<int, Device>
     */
    public static function withoutNestedSubDevices(Collection $devices): Collection
    {
        $ids = $devices->pluck('id')->all();

        if ($ids === []) {
            return $devices;
        }

        $hostsBySub = DeviceLink::whereIn('device_id', $ids)->get()->groupBy('device_id');

        return $devices->reject(function (Device $device) use ($hostsBySub, $ids) {
            $links = $hostsBySub->get($device->id);

            return $links !== null
                && !DeviceModels::standalone($device)
                && $links->contains(fn (DeviceLink $link) => in_array($link->host_device_id, $ids, true));
        })->values();
    }
}
