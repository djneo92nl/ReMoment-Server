<?php

namespace App\Integrations\Sonos;

use App\Domain\Device\BatteryStatus;
use Illuminate\Support\Facades\Http;

/**
 * Battery of a portable Sonos (Roam, Move, …) from the speaker's own status
 * page, `GET :1400/status/batterystatus`. Speakers without a battery answer
 * without a `LocalBatteryStatus`, or with an error: both mean "no battery".
 */
final class SonosBattery
{
    public static function fetch(string $ip): ?BatteryStatus
    {
        try {
            $response = Http::timeout(5)->get("http://{$ip}:1400/status/batterystatus");
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? self::parse($response->body()) : null;
    }

    /** Level and power source from `<LocalBatteryStatus><Data name="Level">87</Data>…`. */
    public static function parse(string $xml): ?BatteryStatus
    {
        if (!preg_match('/<LocalBatteryStatus>(.*?)<\/LocalBatteryStatus>/s', $xml, $block)) {
            return null;
        }

        preg_match_all('/<Data\s+name="([^"]+)">([^<]*)<\/Data>/', $block[1], $pairs, PREG_SET_ORDER);
        $data = array_column($pairs, 2, 1);

        if (!isset($data['Level']) || !is_numeric($data['Level'])) {
            return null;
        }

        // PowerSource: BATTERY, SONOS_CHARGING_RING, USB_POWER.
        $source = $data['PowerSource'] ?? 'BATTERY';

        return new BatteryStatus(max(0, min(100, (int) $data['Level'])), $source !== 'BATTERY');
    }
}
