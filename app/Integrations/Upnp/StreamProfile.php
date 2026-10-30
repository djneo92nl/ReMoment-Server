<?php

namespace App\Integrations\Upnp;

use App\Domain\Storage\Proxies;
use App\Domain\Storage\StreamUrl;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Track;
use Illuminate\Support\Facades\Log;

/**
 * What a renderer is given for one of the server's own files: the original, or a proxy (config/proxies.php) when
 * the file is beyond what the renderer decodes. The limits are the device meta `upnp_limits` (JSON), set by the
 * driver that knows the hardware (`streamium:enable` for the Philips Streamium: lossless only up to 16-bit and
 * 48 kHz, no AAC, found with the quality test of /settings/developer); a renderer without limits always gets the
 * original, as before.
 *
 * A lossless file above the limits (or in a denied codec) becomes `flac-16` (still lossless), a denied lossy one
 * `mp3-high`. A proxy is made before the renderer is told to play: the stream controller sends the original while
 * a proxy is still being made, which the renderer could not decode.
 */
final class StreamProfile
{
    public const KEY = 'upnp_limits';

    private const LOSSLESS = ['flac', 'alac', 'wav', 'aiff', 'wavpack', 'ape', 'tta'];

    /** @return array{max_bit_depth?: int, max_sample_rate?: int, deny_codecs?: string[]}|null */
    public static function limits(Device $device): ?array
    {
        $raw = DeviceMeta::where('device_id', $device->id)->where('key', self::KEY)->value('value');
        $limits = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($limits) && $limits !== [] ? $limits : null;
    }

    /** @param array{max_bit_depth?: int, max_sample_rate?: int, deny_codecs?: string[]} $limits */
    public static function setLimits(Device $device, array $limits): void
    {
        DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => self::KEY], ['value' => json_encode($limits)]);
    }

    /** The proxy profile this renderer needs for the track, or null for the original (also for a file that is not the server's own). */
    public static function for(Device $device, Track $track): ?string
    {
        if (($limits = self::limits($device)) === null || !Proxies::enabled() || !($file = Proxies::original($track))) {
            return null;
        }

        $codec = strtolower((string) ($file->codec ?: $file->extension));
        $lossless = in_array($codec, self::LOSSLESS, true) || str_starts_with($codec, 'pcm_');

        if (in_array($codec, $limits['deny_codecs'] ?? [], true)) {
            return $lossless ? 'flac-16' : 'mp3-high';
        }

        $tooDeep = isset($limits['max_bit_depth']) && ($file->bit_depth ?? 0) > $limits['max_bit_depth'];
        $tooFast = isset($limits['max_sample_rate']) && ($file->sample_rate ?? 0) > $limits['max_sample_rate'];

        return $lossless && ($tooDeep || $tooFast) ? 'flac-16' : null;
    }

    /** The address of the proxy, with its own extension so a renderer that decides from it knows the format. */
    public static function url(int $trackId, string $profile): string
    {
        return StreamUrl::base().'/stream/'.$trackId.'.'.Proxies::extension($profile).'?profile='.$profile;
    }

    /** The profile a stream URL asks for, or null for an original. */
    public static function profileOf(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $profile = $query['profile'] ?? null;

        return is_string($profile) && Proxies::known($profile) ? $profile : null;
    }

    /** Makes the proxy now (a no-op when it exists). False when it could not be made: the caller falls back to the original. */
    public static function prepare(Track $track, string $profile): bool
    {
        if (!($original = Proxies::original($track))) {
            return false;
        }

        try {
            Proxies::generate($original, $profile);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Proxy {$profile} for track {$track->id} could not be made: {$e->getMessage()}");

            return false;
        }
    }
}
