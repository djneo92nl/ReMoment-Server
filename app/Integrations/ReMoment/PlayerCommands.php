<?php

namespace App\Integrations\ReMoment;

use App\Models\Device;
use App\Services\MqttService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Commands for a player, on two channels with the same JSON: MQTT `remoment/playercmd/{id}` (not retained)
 * and a short list in the cache the player can long-poll (`GET /api/players/{token}/commands?since=`).
 */
class PlayerCommands
{
    private const KEEP = 50;

    private const TTL_SECONDS = 300;

    public static function topic(int $deviceId): string
    {
        return "remoment/playercmd/{$deviceId}";
    }

    /** @param  array<string, mixed>  $payload */
    public static function send(Device $device, string $op, array $payload = []): array
    {
        $command = ['id' => self::nextId($device->id), 'op' => $op] + $payload;

        $log = Cache::get(self::logKey($device->id), []);
        $log[] = $command;
        Cache::put(self::logKey($device->id), array_slice($log, -self::KEEP), self::TTL_SECONDS);

        try {
            app(MqttService::class)->publish(self::topic($device->id), json_encode($command), 1);
        } catch (\Throwable $e) {
            // The broker may be down; the player then picks the command up by polling.
            Log::warning("Player command {$op} for device {$device->id} not published: {$e->getMessage()}");
        }

        return $command;
    }

    /** @return array<int, array<string, mixed>> commands after $since (an id), oldest first */
    public static function since(int $deviceId, int $since): array
    {
        return array_values(array_filter(
            Cache::get(self::logKey($deviceId), []),
            fn (array $command) => $command['id'] > $since
        ));
    }

    private static function nextId(int $deviceId): int
    {
        $key = "player:{$deviceId}:command_seq";
        Cache::add($key, 0, self::TTL_SECONDS * 12);

        return (int) Cache::increment($key);
    }

    private static function logKey(int $deviceId): string
    {
        return "player:{$deviceId}:commands";
    }
}
