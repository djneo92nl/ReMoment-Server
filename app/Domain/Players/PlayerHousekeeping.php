<?php

namespace App\Domain\Players;

use App\Domain\Device\DeviceCache;
use App\Integrations\ReMoment\PlayerDriver;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Support\Collection;

/**
 * Keeps stale ReMoment Players from piling up (docs/api/players.md, Housekeeping): `last_seen` of a player,
 * hiding players that have not reported for a while (never deleting them), bringing one back when it reports
 * again, and finding the offline player of the same name for a reinstalled app.
 */
class PlayerHousekeeping
{
    /** Meta key set when the housekeeping (not the admin) hid a player, so only that one is shown again by itself. */
    public const AUTO_HIDDEN = 'player_auto_hidden';

    /** A report only rewrites `last_seen` when the last one is older than this: players report every ~5 seconds. */
    private const TOUCH_SECONDS = 60;

    public static function days(): int
    {
        return max(0, (int) config('players.hide_after_days'));
    }

    /** A player registered or reported: record when, and show it again if the housekeeping had hidden it. */
    public static function touch(Device $device): void
    {
        if (!$device->last_seen || $device->last_seen->lt(now()->subSeconds(self::TOUCH_SECONDS))) {
            $device->forceFill(['last_seen' => now()])->saveQuietly();
        }

        if ($device->hidden && DeviceMeta::where('device_id', $device->id)->where('key', self::AUTO_HIDDEN)->exists()) {
            $device->forceFill(['hidden' => false])->saveQuietly();
            DeviceMeta::where('device_id', $device->id)->where('key', self::AUTO_HIDDEN)->delete();
        }
    }

    /** @return Collection<int, Device> the players the housekeeping would hide: visible, not live, silent for `$days` */
    public static function stale(?int $days = null): Collection
    {
        $days ??= self::days();

        if ($days < 1) {
            return collect();
        }

        $cutoff = now()->subDays($days);

        return Device::query()
            ->where('device_driver', PlayerDriver::class)
            ->where('hidden', false)
            ->where(fn ($q) => $q->where('last_seen', '<', $cutoff)->orWhere(fn ($q) => $q->whereNull('last_seen')->where('created_at', '<', $cutoff)))
            ->get()
            ->reject(fn (Device $device) => DeviceCache::isListenerRunning($device->id))
            ->values();
    }

    /** Hides the stale players. @return int how many */
    public static function hideStale(?int $days = null): int
    {
        $players = self::stale($days);

        foreach ($players as $device) {
            $device->forceFill(['hidden' => true])->saveQuietly();
            DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => self::AUTO_HIDDEN], ['value' => '1']);
        }

        return $players->count();
    }

    /** `GET /api/devices` and `GET /api/players` leave out hidden players unless asked; other hidden devices stay as they were. */
    public static function visible(Collection $devices, bool $includeHidden = false): Collection
    {
        return $includeHidden ? $devices : $devices->reject(fn (Device $d) => $d->hidden && $d->device_driver === PlayerDriver::class)->values();
    }

    /** The offline player with this name and kind that reported last (hidden ones too), for a reinstalled app to take over. */
    public static function adoptable(string $name, string $kind): ?Device
    {
        return Device::query()
            ->where('device_driver', PlayerDriver::class)
            ->where('device_name', $name)
            ->whereIn('id', DeviceMeta::where('key', 'player_kind')->where('value', $kind)->select('device_id'))
            ->orderByRaw('last_seen is null')->orderByDesc('last_seen')->orderByDesc('id')
            ->get()
            ->first(fn (Device $device) => !DeviceCache::isListenerRunning($device->id));
    }
}
