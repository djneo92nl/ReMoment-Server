<?php

namespace App\Domain\System;

use App\Models\Setting;
use Throwable;

/**
 * The last upgrades `app:upgrade` ran, newest first, in Setting `upgrade_history` (a JSON list). Read by /settings/health.
 */
final class UpgradeHistory
{
    public const KEY = 'upgrade_history';

    public const KEEP = 10;

    /** @return list<array{from: ?string, to: string, at: string, result: string, backup: ?string}> */
    public static function all(): array
    {
        try {
            $raw = Setting::get(self::KEY);
        } catch (Throwable) {
            return [];
        }
        $list = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);

        return is_array($list) ? array_values($list) : [];
    }

    public static function latest(): ?array
    {
        return self::all()[0] ?? null;
    }

    /** @return array the entry stored */
    public static function add(?string $from, string $to, string $result, ?string $backup): array
    {
        $entry = ['from' => $from, 'to' => $to, 'at' => now()->toIso8601String(), 'result' => $result, 'backup' => $backup];
        $list = array_slice([$entry, ...self::all()], 0, self::KEEP);
        Setting::set(self::KEY, json_encode($list, JSON_UNESCAPED_SLASHES));

        return $entry;
    }
}
