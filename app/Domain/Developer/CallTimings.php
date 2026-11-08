<?php

namespace App\Domain\Developer;

use Illuminate\Support\Facades\Cache;

/** The last driver calls made from /settings/developer per device, with how long each took. */
final class CallTimings
{
    private const KEEP = 30;

    public static function record(int $deviceId, string $action, int $ms, bool $ok): void
    {
        $rows = self::recent($deviceId);
        array_unshift($rows, ['at' => now()->format('H:i:s'), 'action' => $action, 'ms' => $ms, 'ok' => $ok]);
        Cache::put(self::key($deviceId), array_slice($rows, 0, self::KEEP), now()->addDay());
    }

    /** @return array<int, array{at: string, action: string, ms: int, ok: bool}> newest first */
    public static function recent(int $deviceId): array
    {
        return Cache::get(self::key($deviceId), []);
    }

    public static function forget(int $deviceId): void
    {
        Cache::forget(self::key($deviceId));
    }

    private static function key(int $deviceId): string
    {
        return "dev:timings:{$deviceId}";
    }
}
