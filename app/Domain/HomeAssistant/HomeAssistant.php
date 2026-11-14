<?php

namespace App\Domain\HomeAssistant;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Home Assistant MQTT discovery settings (docs/architecture/home-assistant.md). Opt-in: nothing is published
 * until the admin switches it on (Setting `ha_discovery_enabled`, falling back to HA_DISCOVERY_ENABLED).
 */
final class HomeAssistant
{
    public const ENABLED = 'ha_discovery_enabled';

    public const PREFIX = 'ha_discovery_prefix';

    /** Retained availability of every entity: `online` while discovery runs, `offline` after a removal. */
    public const STATUS_TOPIC = 'remoment/ha/status';

    private const ENABLED_CACHE = 'ha_discovery_enabled_flag';

    public static function enabled(): bool
    {
        // Cached for a minute: the event listeners ask on every state change.
        try {
            return (bool) Cache::remember(self::ENABLED_CACHE, 60, function () {
                $value = Setting::get(self::ENABLED);

                return $value === null ? (bool) config('mqtt.ha_discovery_enabled') : $value === '1';
            });
        } catch (\Throwable) {
            return false;   // no database yet (a fresh install mid-migration): off, never an error in the caller
        }
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::set(self::ENABLED, $enabled ? '1' : '0');
        Cache::forget(self::ENABLED_CACHE);
    }

    public static function prefix(): string
    {
        $prefix = trim((string) Setting::get(self::PREFIX, config('mqtt.ha_discovery_prefix')), '/');

        return $prefix !== '' ? $prefix : 'homeassistant';
    }

    public static function setPrefix(string $prefix): void
    {
        Setting::set(self::PREFIX, trim($prefix, '/'));
    }
}
