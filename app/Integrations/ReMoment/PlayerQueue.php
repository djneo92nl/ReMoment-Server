<?php

namespace App\Integrations\ReMoment;

use Illuminate\Support\Facades\Cache;

/**
 * The queue of one ReMoment player. The server owns it; the player only plays the track it is told to.
 * Kept in the cache (a week), so a restart of the player or the server resumes where it was.
 */
class PlayerQueue
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

    public function current(): ?int
    {
        return $this->trackIds[$this->index] ?? null;
    }

    /** @return int[] the tracks after the current one */
    public function upNext(int $limit): array
    {
        return array_slice($this->trackIds, $this->index + 1, $limit);
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
