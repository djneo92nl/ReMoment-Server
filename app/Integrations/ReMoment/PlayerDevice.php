<?php

namespace App\Integrations\ReMoment;

use App\Domain\Artwork\LibraryArtwork;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Support\Str;

class PlayerDevice
{
    public const KINDS = ['web', 'ios', 'esp32'];

    /** Capabilities every kind of player has; the rest is reported by the player at registration. */
    public const OPTIONAL = ['seek', 'queue', 'queue_jump', 'shuffle', 'repeat', 'volume_control'];

    /** Browser-decodable by default; a player reports its own list. */
    public const DEFAULT_FORMATS = ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/flac', 'audio/ogg', 'audio/wav', 'audio/x-wav'];

    /** The cover URL of a track: its album's first image (a string or {url}), else the track's own. */
    public static function cover(\App\Models\Media\Track $track): ?string
    {
        return LibraryArtwork::coverUrl($track->album?->images ?? []) ?? LibraryArtwork::coverUrl($track->images ?? []);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?Device
    {
        $meta = DeviceMeta::where('key', 'player_token')->where('value', self::hash($token))->first();

        return $meta?->device;
    }

    /**
     * @param  string[]|null  $capabilities  optional capabilities the player supports (null: all)
     * @param  string[]|null  $formats  MIME types the player decodes (null: the defaults)
     * @return array{0: Device, 1: string} the device and the player token (new when none was given or it was unknown)
     */
    public static function register(?string $token, string $name, string $kind, ?array $capabilities = null, ?array $formats = null): array
    {
        $device = $token ? self::findByToken($token) : null;

        if (!$device) {
            $token = Str::random(48);
            $device = Device::create([
                'device_name' => $name,
                'device_brand_name' => 'ReMoment',
                'device_driver_name' => 'ReMoment Player',
                'device_product_type' => 'Player',
                'device_driver' => PlayerDriver::class,
                'ip_address' => null,
            ]);
            self::setMeta($device, 'player_token', self::hash($token));
        } else {
            $device->update(['device_name' => $name]);
        }

        self::setMeta($device, 'player_kind', $kind);
        self::setMeta($device, 'player_capabilities', json_encode(array_values(array_intersect($capabilities ?? self::OPTIONAL, self::OPTIONAL))));
        self::setMeta($device, 'player_formats', json_encode($formats ?: self::DEFAULT_FORMATS));

        return [$device, $token];
    }

    /** @return string[] */
    public static function capabilities(Device $device): array
    {
        return self::jsonMeta($device, 'player_capabilities', self::OPTIONAL);
    }

    /** @return string[] */
    public static function formats(Device $device): array
    {
        return self::jsonMeta($device, 'player_formats', self::DEFAULT_FORMATS);
    }

    public static function setMeta(Device $device, string $key, string $value): void
    {
        DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => $key], ['value' => $value]);
    }

    private static function jsonMeta(Device $device, string $key, array $default): array
    {
        $value = DeviceMeta::where('device_id', $device->id)->where('key', $key)->value('value');

        return $value ? (json_decode($value, true) ?: $default) : $default;
    }
}
