<?php

namespace App\Domain\Radio\Relay;

use App\Models\RadioStation;
use Illuminate\Support\Facades\Cache;

/**
 * What the relay manager (`radio:relay`, its own process) reports to the web side through the cache: the relays
 * running or waiting to restart, and when it last looked. Read by `GET /api/radio/relays` and /settings/health.
 */
final class RelayStatus
{
    private const KEY = 'radio:relay:status';

    /** A manager that has not reported for this long is down. */
    public const STALE_SECONDS = 15;

    /** @param array<int, array<string, mixed>> $relays station id => row of {@see RelayManager::status()} */
    public static function publish(array $relays, int $now): void
    {
        Cache::put(self::KEY, ['at' => $now, 'relays' => $relays], 300);
    }

    /** Whether the manager reported recently. */
    public static function managerAlive(): bool
    {
        $at = Cache::get(self::KEY)['at'] ?? null;

        return $at !== null && now()->timestamp - $at <= self::STALE_SECONDS;
    }

    /**
     * @return array{enabled: bool, manager: bool, max: int, data: list<array<string, mixed>>}
     */
    public static function report(): array
    {
        $cached = Cache::get(self::KEY);
        $alive = self::managerAlive();
        $rows = $alive ? ($cached['relays'] ?? []) : [];
        $names = RadioStation::whereIn('id', array_keys($rows))->pluck('name', 'id');

        $data = [];

        foreach ($rows as $id => $row) {
            $data[] = [
                'station_id' => (int) $id,
                'station' => $names[$id] ?? null,
                'state' => $row['state'],
                'since' => $row['since'] !== null ? date('c', $row['since']) : null,
                'listeners' => $row['listeners'],
                'failures' => (int) $row['failures'],
                'retry_at' => $row['retry_at'] !== null ? date('c', $row['retry_at']) : null,
                'mount' => RelayCommand::mount((int) $id),
                'url' => RadioRelay::urlFor((int) $id),
            ];
        }

        return [
            'enabled' => RadioRelay::enabled(),
            'manager' => $alive,
            'max' => (int) config('radio_relay.max'),
            'data' => $data,
        ];
    }
}
