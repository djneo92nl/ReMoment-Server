<?php

namespace App\Domain\Radio\Relay;

use App\Integrations\Upnp\StreamProfile;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\RadioStation;
use Closure;

/**
 * Does this renderer need the relay for this station? The device meta `radio_relay` says: `never`, `always`, or
 * `auto` (the default): relay only what the device's limits (`upnp_limits`, see Upnp\StreamProfile) say it cannot
 * play: `no_dns` (it cannot resolve a name: every station by name), `no_tls` (an HTTPS stream), `no_playlists`
 * (a `.pls`/`.m3u`/HLS address) and `deny_codecs` (a codec it cannot decode). A device without limits is never
 * relayed in `auto`: what plays direct today keeps playing direct.
 */
final class RelayDecision
{
    public const KEY = 'radio_relay';

    public const MODES = ['auto', 'always', 'never'];

    public static function mode(Device $device): string
    {
        $mode = DeviceMeta::where('device_id', $device->id)->where('key', self::KEY)->value('value');

        return in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    public static function setMode(Device $device, string $mode): void
    {
        DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => self::KEY], ['value' => in_array($mode, self::MODES, true) ? $mode : 'auto']);
    }

    /** @param Closure(RadioStation): array|null $facts what the station serves (default: `StationProbe::facts`); only asked when a limit needs it */
    public static function needed(Device $device, RadioStation $station, ?Closure $facts = null): bool
    {
        if (!RadioRelay::enabled() || $station->streamUrl() === null) {
            return false;
        }

        $mode = self::mode($device);

        if ($mode !== 'auto') {
            return $mode === 'always';
        }

        $limits = StreamProfile::limits($device) ?? [];

        if (array_intersect_key($limits, array_flip(['no_dns', 'no_tls', 'no_playlists', 'deny_codecs'])) === []) {
            return false;
        }

        $facts = ($facts ?? StationProbe::facts(...))($station);

        return match (true) {
            !empty($limits['no_dns']) && !$facts['ip_host'] => true,
            !empty($limits['no_tls']) && $facts['scheme'] === 'https' => true,
            !empty($limits['no_playlists']) && $facts['playlist'] => true,
            in_array($facts['codec'], $limits['deny_codecs'] ?? [], true) => true,
            default => false,
        };
    }
}
