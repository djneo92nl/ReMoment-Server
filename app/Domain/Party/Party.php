<?php

namespace App\Domain\Party;

use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Support\Str;

/**
 * The party page of a device: a link (QR code) behind which guests add tracks to that device's queue, with a
 * limit per visitor and no other controls. Kept as JSON in the device meta key `party`; the token in the link
 * is the only key, so "New link" shuts out everyone who has the old one. See docs/frontend/party.md.
 */
final class Party
{
    public const META_KEY = 'party';

    private const DEFAULTS = ['enabled' => false, 'token' => null, 'limit' => 3, 'window_minutes' => 10];

    /** @return array{enabled: bool, token: ?string, limit: int, window_minutes: int} */
    public static function for(Device $device): array
    {
        $value = DeviceMeta::where('device_id', $device->id)->where('key', self::META_KEY)->value('value');
        $stored = is_string($value) ? (json_decode($value, true) ?: []) : [];

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    /** @return array{enabled: bool, token: ?string, limit: int, window_minutes: int} */
    public static function enable(Device $device, ?int $limit = null, ?int $windowMinutes = null): array
    {
        $party = self::for($device);

        return self::save($device, [
            'enabled' => true,
            'token' => $party['token'] ?? self::newToken(),
            'limit' => max(1, min(50, $limit ?? $party['limit'])),
            'window_minutes' => max(1, min(240, $windowMinutes ?? $party['window_minutes'])),
        ]);
    }

    public static function disable(Device $device): array
    {
        return self::save($device, ['enabled' => false] + self::for($device));
    }

    /** A new link: the old one stops working. */
    public static function rotate(Device $device): array
    {
        return self::save($device, ['token' => self::newToken()] + self::for($device));
    }

    public static function url(array $party): ?string
    {
        return $party['enabled'] && $party['token'] ? url('/party/'.$party['token']) : null;
    }

    /** The device whose party is on behind this token; null for an unknown, old or switched-off link. */
    public static function deviceForToken(string $token): ?Device
    {
        if (!preg_match('/^[A-Za-z0-9]{20,64}$/', $token)) {
            return null;
        }

        $ids = DeviceMeta::where('key', self::META_KEY)->where('value', 'like', '%'.$token.'%')->pluck('device_id');

        return Device::whereIn('id', $ids)->get()->first(function (Device $device) use ($token) {
            $party = self::for($device);

            return $party['enabled'] && hash_equals((string) $party['token'], $token);
        });
    }

    private static function save(Device $device, array $party): array
    {
        $party = array_merge(self::DEFAULTS, array_intersect_key($party, self::DEFAULTS));
        DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => self::META_KEY], ['value' => json_encode($party)]);

        return $party;
    }

    private static function newToken(): string
    {
        return Str::random(32);
    }
}
