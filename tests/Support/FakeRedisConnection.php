<?php

namespace Tests\Support;

/** The few list and sorted-set commands the Redis queue store uses, in memory (no Redis needed). Lists: first element = head. */
class FakeRedisConnection
{
    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var array<string, array<string, int>> member => score */
    public array $sets = [];

    public function rpush(string $key, string $value): void
    {
        $this->lists[$key][] = $value;
    }

    public function lpush(string $key, string $value): void
    {
        array_unshift($this->lists[$key], $value);
    }

    public function lindex(string $key, int $index): ?string
    {
        return $this->lists[$key][$index] ?? null;
    }

    public function llen(string $key): int
    {
        return count($this->lists[$key] ?? []);
    }

    /** @return list<string> */
    public function lrange(string $key, int $start, int $stop): array
    {
        $list = $this->lists[$key] ?? [];

        return array_slice($list, $start, $stop < 0 ? null : $stop - $start + 1);
    }

    public function lrem(string $key, int $count, string $value): int
    {
        $at = array_search($value, $this->lists[$key] ?? [], true);
        if ($at === false) {
            return 0;
        }
        array_splice($this->lists[$key], $at, 1);

        return 1;
    }

    public function zadd(string $key, int $score, string $member): void
    {
        $this->sets[$key][$member] = $score;
    }

    public function zcard(string $key): int
    {
        return count($this->sets[$key] ?? []);
    }

    public function zrem(string $key, string $member): int
    {
        $had = isset($this->sets[$key][$member]);
        unset($this->sets[$key][$member]);

        return (int) $had;
    }

    /** @return array<int|string, mixed> members, or member => score with `withscores` */
    public function zrange(string $key, int $start, int $stop, array $options = []): array
    {
        $set = $this->sets[$key] ?? [];
        asort($set);
        $slice = array_slice($set, $start, $stop - $start + 1, true);

        return ($options['withscores'] ?? false) ? $slice : array_keys($slice);
    }

    /** A queued job payload as the Redis queue writes it. */
    public static function payload(string $class, ?int $pushedAt = null, array $extra = []): string
    {
        return json_encode(['uuid' => uniqid(), 'displayName' => $class, 'pushedAt' => $pushedAt ?? now()->timestamp, 'data' => ['commandName' => $class, 'command' => ''], ...$extra]);
    }
}
