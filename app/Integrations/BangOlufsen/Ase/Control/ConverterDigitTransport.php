<?php

namespace App\Integrations\BangOlufsen\Ase\Control;

use App\Domain\Control\ControlProfile;
use App\Domain\Control\ControlTransport;
use App\Domain\Device\DeviceCache;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver;
use App\Integrations\BangOlufsen\Ase\VideoPlayerDriver;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;

/**
 * FM presets of a BeoLink Converter NL/ML's tuner, pressed from an ASE player
 * that has borrowed the converter's RADIO source. The player forwards its keys
 * as Beo4 telegrams over Network Link and the converter puts them on the
 * Master Link bus:
 *
 * - `preset_1` .. `preset_9`: `POST BeoZone/Zone/Digits` on the player (one digit per call).
 * - `next_preset` / `prev_preset`: the player's transport `Stream/Forward` / `Stream/Backward`
 *   (verified: the converter's `NOW_PLAYING_LEGACY.trackNumber` went 3 -> 4 -> 3).
 *
 * Sending the same calls to the converter itself does nothing useful (it logs an
 * IR command for the ML product and the tuner ignores it), so the source must
 * be borrowed and the command goes to the player. Tuning (`tune_up`/`tune_down`)
 * has no known call and is not offered.
 * See docs/architecture/ase-api-map.md, "NetworkLink/MasterLink converter".
 */
final class ConverterDigitTransport implements ControlTransport
{
    /** The product type in a converter's jid: "1790.{item}.{serial}@products.bang-olufsen.com". */
    private const CONVERTER_PRODUCT_TYPE = '1790';

    public function supports(Device $device, ControlProfile $profile): bool
    {
        return $profile->key === 'fm'
            && $device->ip_address
            && $this->isAsePlayer($device)
            && $this->playsConverterSource($device);
    }

    public function commands(Device $device, ControlProfile $profile): ?array
    {
        return ['prev_preset', 'next_preset', ...array_map(fn (int $digit) => "preset_{$digit}", range(1, 9))];
    }

    public function send(Device $device, ControlProfile $profile, string $commandId): void
    {
        $player = app(HttpConnector::class, ['baseUrl' => $device->ip_address.':8080']);

        match (true) {
            $commandId === 'next_preset' => $player->postStrict('BeoZone/Zone/Stream/Forward', ['toBeReleased' => false]),
            $commandId === 'prev_preset' => $player->postStrict('BeoZone/Zone/Stream/Backward', ['toBeReleased' => false]),
            (bool) preg_match('/^preset_([1-9])$/', $commandId, $match) => $player->postStrict('BeoZone/Zone/Digits', ['digits' => (int) $match[1]]),
            default => throw new UnsupportedOperationException("The converter tuner has no call for '{$commandId}'."),
        };
    }

    private function isAsePlayer(Device $device): bool
    {
        return is_a((string) $device->device_driver, MusicPlayerDriver::class, true)
            || is_a((string) $device->device_driver, VideoPlayerDriver::class, true);
    }

    /** Whether the device is playing a source that belongs to a converter (its own source jid names the product). */
    private function playsConverterSource(Device $device): bool
    {
        $jid = DeviceCache::getNowPlaying($device->id)?->source?->jid;

        return $jid !== null
            && preg_match('/(?:^|:)'.self::CONVERTER_PRODUCT_TYPE.'\.\d+\.\d+@/', $jid) === 1;
    }
}
