<?php

namespace App\Domain\Radio\Relay;

use App\Models\RadioStation;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Owns the ffmpeg children of the radio relay: one per wanted station (`RadioRelay::wanted()`), started on demand,
 * stopped when nobody asked for it for `radio_relay.idle_seconds` and nobody listens, restarted with a backoff when
 * one dies, never more than `radio_relay.max` at once. `tick()` is one pass; `radio:relay` calls it every second.
 */
final class RelayManager
{
    /** Seconds between asking Icecast who listens. */
    private const STATUS_EVERY_SECONDS = 5;

    /** Seconds to wait before restarting a relay, by how many times in a row it died soon after starting. */
    private const BACKOFF_SECONDS = [3, 10, 30, 60];

    /** A relay that ran this long was working: its death is a new failure, not part of a streak. */
    private const HEALTHY_SECONDS = 30;

    /** @var array<int, array{process: RelayProcess, started: int, seen_listeners: int}> station id => running relay */
    private array $running = [];

    /** @var array<int, array{failures: int, until: int}> station id => when it may start again */
    private array $failed = [];

    private int $nextStatus = 0;

    /**
     * @param  Closure(string[]): RelayProcess  $launcher  starts an ffmpeg
     * @param  Closure(): int  $clock  now, as a timestamp
     * @param  int[]  $pinned  stations kept relayed while this runs (`radio:relay --station=`)
     */
    public function __construct(
        private readonly Closure $launcher,
        private readonly IcecastStatus $icecast,
        private readonly string $ffmpeg,
        private readonly Closure $clock,
        private readonly array $pinned = [],
    ) {}

    public function tick(): void
    {
        $now = ($this->clock)();

        $this->reap($now);
        $this->refreshListeners($now);
        $this->stopIdle($now);
        $this->startWanted($now);

        Cache::put('radio:relay:status', $this->status(), 120);
    }

    /** @return int[] the stations running now */
    public function running(): array
    {
        return array_keys($this->running);
    }

    public function stopAll(): void
    {
        foreach (array_keys($this->running) as $id) {
            $this->stop($id, 'shutting down');
        }
    }

    /** @return array<int, array{since: int, listeners: int|null}> */
    public function status(): array
    {
        return array_map(fn (array $relay) => ['since' => $relay['started'], 'listeners' => $relay['listeners'] ?? null], $this->running);
    }

    private function reap(int $now): void
    {
        foreach ($this->running as $id => $relay) {
            if ($relay['process']->running()) {
                continue;
            }

            unset($this->running[$id]);

            $streak = ($now - $relay['started']) >= self::HEALTHY_SECONDS ? 0 : ($this->failed[$id]['failures'] ?? 0);
            $failures = $streak + 1;
            $wait = self::BACKOFF_SECONDS[min($failures, count(self::BACKOFF_SECONDS)) - 1];
            $this->failed[$id] = ['failures' => $failures, 'until' => $now + $wait];

            Log::warning("Radio relay {$id}: ffmpeg ended (exit ".($relay['process']->exitCode() ?? '?')."), restart in {$wait}s: ".$relay['process']->tail());
        }
    }

    private function refreshListeners(int $now): void
    {
        if ($this->running === [] || $now < $this->nextStatus) {
            return;
        }

        $this->nextStatus = $now + self::STATUS_EVERY_SECONDS;
        $mounts = $this->icecast->mounts();

        foreach ($this->running as $id => &$relay) {
            $listeners = $mounts[RelayCommand::mount($id)]['listeners'] ?? null;
            $relay['listeners'] = $listeners;

            if ($listeners) {
                $relay['seen_listeners'] = $now;
            }
        }

        unset($relay);
    }

    private function stopIdle(int $now): void
    {
        $idle = (int) config('radio_relay.idle_seconds');
        $wanted = $this->demand($now);

        foreach (array_keys($this->running) as $id) {
            $last = max($wanted[$id] ?? 0, $this->running[$id]['seen_listeners'], $this->running[$id]['started']);

            if ($now - $last > $idle) {
                $this->stop($id, 'idle');
            }
        }
    }

    private function startWanted(int $now): void
    {
        $idle = (int) config('radio_relay.idle_seconds');

        // Most recently asked for first; an old request is not demand any more.
        $candidates = collect($this->demand($now))
            ->filter(fn (int $at, int $id) => $now - $at <= $idle && !isset($this->running[$id]) && ($this->failed[$id]['until'] ?? 0) <= $now)
            ->sortDesc();

        foreach ($candidates as $id => $at) {
            if (count($this->running) >= (int) config('radio_relay.max') && !$this->freeASlot($now)) {
                return;
            }

            $this->start($id, $now);
        }
    }

    /** Stops the relay nobody listens to and nobody asked for the longest; false when every one is in use. */
    private function freeASlot(int $now): bool
    {
        $wanted = $this->demand($now);
        $victim = collect($this->running)
            ->filter(fn (array $relay) => empty($relay['listeners']))
            ->sortBy(fn (array $relay, int $id) => $wanted[$id] ?? 0)
            ->keys()
            ->first();

        if ($victim === null) {
            return false;
        }

        $this->stop($victim, 'making room');

        return true;
    }

    private function start(int $id, int $now): void
    {
        $url = RadioStation::with('meta')->find($id)?->streamUrl();

        if ($url === null) {
            RadioRelay::release($id);
            Log::warning("Radio relay {$id}: the station has no stream address.");

            return;
        }

        try {
            $process = ($this->launcher)(RelayCommand::build($this->ffmpeg, $url, $id));
        } catch (\Throwable $e) {
            $this->failed[$id] = ['failures' => 1, 'until' => $now + self::BACKOFF_SECONDS[0]];
            Log::warning("Radio relay {$id}: ffmpeg did not start: {$e->getMessage()}");

            return;
        }

        $this->running[$id] = ['process' => $process, 'started' => $now, 'seen_listeners' => 0];
        Log::info("Radio relay {$id}: started ({$url}).");
    }

    private function stop(int $id, string $why): void
    {
        $this->running[$id]['process']->stop();
        unset($this->running[$id]);
        Log::info("Radio relay {$id}: stopped ({$why}).");
    }

    /** @return array<int, int> station id => last asked for, with the pinned ones counting as asked for now */
    private function demand(int $now): array
    {
        return array_replace(RadioRelay::wanted(), array_fill_keys($this->pinned, $now));
    }
}
