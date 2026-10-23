<?php

namespace App\Integrations\ReMoment;

use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Storage\Proxies;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Support\Str;

class PlayerDevice
{
    public const KINDS = ['web', 'ios', 'esp32', 'retro'];

    /** Capabilities every kind of player has; the rest is reported by the player at registration. */
    public const OPTIONAL = ['seek', 'queue', 'queue_jump', 'shuffle', 'repeat', 'volume_control', 'radio_control', 'stream_url'];

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
    public static function register(?string $token, string $name, string $kind, ?array $capabilities = null, ?array $formats = null, ?int $adopt = null, ?string $profile = null): array
    {
        $device = $token ? self::findByToken($token) : null;
        $adopted = false;

        // Another browser takes over an existing player instead of making a new one: it gets a token of its own.
        if (!$device && $adopt) {
            $device = Device::where('device_driver', PlayerDriver::class)->find($adopt);
            $adopted = $device !== null;

            if ($adopted) {
                $token = Str::random(48);
                DeviceMeta::create(['device_id' => $device->id, 'key' => 'player_token', 'value' => self::hash($token)]);
            }
        }

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
        } elseif (!$adopted) {
            $device->update(['device_name' => $name]);
        }

        if (!$adopted) {
            self::setMeta($device, 'player_kind', $kind);
            self::setMeta($device, 'player_capabilities', json_encode(array_values(array_intersect($capabilities ?? self::OPTIONAL, self::OPTIONAL))));
            self::setMeta($device, 'player_formats', json_encode($formats ?: self::DEFAULT_FORMATS));
            self::setMeta($device, 'player_profile', Proxies::known($profile) ? $profile : '');
        }

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

    /** The proxy profile the player asked for at registration (a small copy of every track), or null. */
    public static function profile(Device $device): ?string
    {
        $profile = DeviceMeta::where('device_id', $device->id)->where('key', 'player_profile')->value('value');

        return Proxies::known($profile) ? $profile : null;
    }

    /**
     * What a track whose file has this MIME type is played from: the original (null) or a proxy profile.
     * A requested profile always wins; else the original when the player decodes it; else the first
     * profile in a format it decodes. False when the player can play it neither way.
     */
    public static function source(Device $device, ?string $mime): string|false|null
    {
        if ($profile = self::profile($device)) {
            return $profile;
        }

        $formats = self::formats($device);

        if ($mime === null || in_array($mime, $formats, true)) {
            return null;
        }

        foreach (Proxies::profiles() as $name => $profile) {
            if (in_array($profile['mime'], $formats, true) && Proxies::enabled()) {
                return $name;
            }
        }

        return false;
    }

    public static function kind(Device $device): string
    {
        return DeviceMeta::where('device_id', $device->id)->where('key', 'player_kind')->value('value') ?? 'web';
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
