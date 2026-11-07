<?php

namespace App\Domain\System;

use App\Models\Setting;
use Throwable;

/**
 * The application version: what the code says (VERSION file via config('app.version')) and what the database
 * recorded after the last upgrade (Setting `app_version`). An upgrade records after migrating; see
 * docs/architecture/v1-roadmap.md workstream 9a.
 */
final class InstalledVersion
{
    public const KEY = 'app_version';

    public static function current(): string
    {
        return (string) config('app.version');
    }

    /** The version recorded in the database, or null when none was recorded or the database cannot be read. */
    public static function recorded(): ?string
    {
        try {
            $value = Setting::get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Record the running code's version as the installed one and return it. */
    public static function record(): string
    {
        $version = self::current();
        Setting::set(self::KEY, $version);

        return $version;
    }
}
