<?php

namespace App\Domain\SubDevices;

use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\DeviceMeta;

/**
 * Links a device reports itself: an ASE player lists the products it knows in `BeoZone/System/Products`, among them a
 * BeoLink Converter with its `borrowSource` switch. A product that is one of our devices (its product id is the device's
 * `converter_jid`) is offered as a `borrow` link with the player as host. Nothing else is guessed: an HDMI input is
 * linked by hand. The shape of the Products list is unverified (the docs only say each entry has `borrowSource`), so
 * both a `[jid, {...}]` pair and a plain object are read.
 */
final class LinkSuggestions
{
    /**
     * @param  array<string, mixed>  $products  the answer of `getProducts()`
     * @return array<int, array{device_id: int, device_name: string, kind: string, via: string, borrowing: bool}>
     */
    public static function fromProducts(Device $host, array $products): array
    {
        $suggestions = [];

        foreach ($products['products'] ?? [] as $entry) {
            [$jid, $data] = self::read($entry);

            if ($jid === null) {
                continue;
            }

            $sub = self::deviceFor($jid);

            if ($sub === null || $sub->id === $host->id) {
                continue;
            }

            if (DeviceLink::where('device_id', $sub->id)->where('host_device_id', $host->id)->where('kind', DeviceLink::KIND_BORROW)->where('via', '')->exists()) {
                continue;
            }

            $borrow = $data['borrowSource'] ?? false;
            $suggestions[] = [
                'device_id' => $sub->id,
                'device_name' => (string) $sub->device_name,
                'kind' => DeviceLink::KIND_BORROW,
                'via' => '',
                'borrowing' => (bool) (is_array($borrow) ? ($borrow['value'] ?? false) : $borrow),
            ];
        }

        return $suggestions;
    }

    /** @return array{0: ?string, 1: array} */
    private static function read(mixed $entry): array
    {
        if (!is_array($entry)) {
            return [null, []];
        }

        $data = is_array($entry[1] ?? null) ? $entry[1] : $entry;
        $jid = $data['jid'] ?? $data['product']['jid'] ?? (is_string($entry[0] ?? null) ? $entry[0] : null);

        return [is_string($jid) && $jid !== '' ? $jid : null, $data];
    }

    private static function deviceFor(string $jid): ?Device
    {
        $deviceId = DeviceMeta::whereIn('key', LinkMatcher::IDENTITY_KEYS)->where('value', $jid)->value('device_id');

        return $deviceId === null ? null : Device::find($deviceId);
    }
}
