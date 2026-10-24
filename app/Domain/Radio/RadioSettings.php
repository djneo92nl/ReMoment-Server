<?php

namespace App\Domain\Radio;

use App\Models\Setting;

class RadioSettings
{
    public const NTS_ENABLED = 'radio_nts_enabled';

    /**
     * Whether the NTS features run (default off): NTS' unofficial website data for live show titles, the show archive and
     * tracklists, and the importer. NTS publishes no terms that allow it, so it is opt-in (docs/architecture/radio.md).
     */
    public static function ntsEnabled(): bool
    {
        return Setting::get(self::NTS_ENABLED) === '1';
    }
}
