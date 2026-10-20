<?php

namespace App\Integrations\Common;

use Illuminate\Support\Facades\Cache;

/**
 * The queue of a device that has none of its own (a ReMoment player, a UPnP renderer). The server owns it; the
 * device only plays the track it is told to. Kept in the cache (a week), so a restart of the device or the
 * server resumes where it was. The cache key predates the UPnP driver and is kept so stored queues survive.
 */
class DeviceQueue
{
    private const TTL_DAYS = 7;

    /** @param  int[]  $trackIds */
    public function __construct(
        public array $trackIds = [],
        public int $index = 0,
        public bool $shuffle = false,
        public string $repeat = 'off',
    ) {}

    public static function for(int $deviceId): self
    {
        $data = Cache::get(self::key($deviceId));

        return is_array($data)
            ? new self($data['track_ids'] ?? [], $data['index'] ?? 0, $data['shuffle'] ?? false, $data['repeat'] ?? 'off')
            : new self;
    }

    public function save(int $deviceId): void
    {
        Cache::put(self::key($deviceId), [
            'track_ids' => $this->trackIds,
            'index' => $this->index,
            'shuffle' => $this->shuffle,
            'repeat' => $this->repeat,
        ], now()->addDays(self::TTL_DAYS));
    }

    /** @param  int[]  $trackIds */
    public function replace(array $trackIds): void
    {
        $this->trackIds = array_values($trackIds);
        $this->index = 0;
    }

    /** @param  int[]  $trackIds  added after the last track */
    public function append(array $trackIds): void
    {
        $this->trackIds = array_merge($this->trackIds, array_values($trackIds));
    }

    /** @param  int[]  $trackIds  put right after the current track, in this order */
    public function insertNext(array $trackIds): void
    {
        $at = $this->trackIds ? $this->index + 1 : 0;
        array_splice($this->trackIds, $at, 0, array_values($trackIds));
    }

    /** The track `$position` places after the current one (1 = the first of upNext()), null when there is none. */
    public function at(int $position): ?int
    {
        return $position >= 1 ? ($this->trackIds[$this->index + $position] ?? null) : null;
    }

    /** Removes the entry `$position` places after the current one; false when there is none. */
    public function remove(int $position): bool
    {
        if ($this->at($position) === null) {
            return false;
        }

        array_splice($this->trackIds, $this->index + $position, 1);

        return true;
    }

    /** Moves the entry at up-next position `$from` so it ends up at `$to`; false when either is outside the up-next list. */
    public function move(int $from, int $to): bool
    {
        if ($this->at($from) === null || $this->at($to) === null) {
            return false;
        }

        [$id] = array_splice($this->trackIds, $this->index + $from, 1);
        array_splice($this->trackIds, $this->index + $to, 0, [$id]);

        return true;
    }

    /** Drops everything after the current track. */
    public function clear(): void
    {
        $this->trackIds = array_slice($this->trackIds, 0, $this->trackIds ? $this->index + 1 : 0);
    }

    /** Turns shuffle on (the tracks after the current one are shuffled) or off. */
    public function setShuffle(bool $shuffle): void
    {
        $this->shuffle = $shuffle;

        if ($shuffle) {
            $rest = array_slice($this->trackIds, $this->index + 1);
            shuffle($rest);
            $this->trackIds = array_merge(array_slice($this->trackIds, 0, $this->index + 1), $rest);
        }
    }

    public function current(): ?int
    {
        return $this->trackIds[$this->index] ?? null;
    }

    /** @return int[] the tracks after the current one */
    public function upNext(int $limit): array
    {
        return array_slice($this->trackIds, $this->index + 1, $limit);
    }

    /** The track that plays after the current one, without moving to it (null at the end of the queue). */
    public function peekNext(): ?int
    {
        if ($this->repeat === 'one') {
            return $this->current();
        }

        if ($this->index + 1 < count($this->trackIds)) {
            return $this->trackIds[$this->index + 1];
        }

        return $this->repeat === 'all' ? ($this->trackIds[0] ?? null) : null;
    }

    /** Moves to the next track; null at the end of the queue (repeat-all wraps, repeat-one stays). */
    public function advance(bool $manual = false): ?int
    {
        if ($this->repeat === 'one' && !$manual) {
            return $this->current();
        }

        if ($this->index + 1 < count($this->trackIds)) {
            $this->index++;
        } elseif ($this->repeat === 'all' && $this->trackIds) {
            $this->index = 0;
        } else {
            return null;
        }

        return $this->current();
    }

    public function back(): ?int
    {
        $this->index = max(0, $this->index - 1);

        return $this->current();
    }

    public function jump(int $offset): ?int
    {
        $target = $this->index + $offset;

        if ($target < 0 || $target >= count($this->trackIds)) {
            return null;
        }

        $this->index = $target;

        return $this->current();
    }

    private static function key(int $deviceId): string
    {
        return "player:{$deviceId}:queue";
    }
}
