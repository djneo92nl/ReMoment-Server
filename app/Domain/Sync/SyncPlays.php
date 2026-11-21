<?php

namespace App\Domain\Sync;

use App\Domain\Library\IdAliases;
use App\Integrations\ReMoment\PlayerDevice;
use App\Integrations\ReMoment\PlayerDriver;
use App\Jobs\ScrobbleToLastfm;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Track;
use App\Models\Play;
use App\Models\SyncTarget;
use Carbon\CarbonImmutable;

/**
 * Plays an app target made while it was offline (docs/architecture/offline-sync.md section 7, step 6): written into the
 * history with `source_type = offline` and the original time, and scrobbled to Last.fm with that time when it is young
 * enough. Idempotent: the app queues plays and re-sends them until the server answers, so the same play (same track, same
 * start) on the same target is never stored twice. No schema of its own: the plays go to a hidden player device that
 * belongs to the target (its id is in the device's meta `sync_target`).
 */
class SyncPlays
{
    public const SOURCE = 'offline';

    /** Last.fm refuses a scrobble older than two weeks (its API documents it; unverified against the live service). */
    public const SCROBBLE_MAX_AGE_DAYS = 14;

    public const MAX_PER_REQUEST = 500;

    /** A start this far ahead of the server's clock is a wrong clock on the phone, not a play. */
    private const FUTURE_SLACK_MINUTES = 10;

    /**
     * @param  list<array<string, mixed>>  $plays  `{ client_id, track_id, started_at, played_seconds }`, already shape-validated
     * @return array{accepted: int, duplicates: int, rejected: list<array{client_id: string, error: string}>, scrobbled: int, too_old: int}
     */
    public static function record(SyncTarget $target, array $plays): array
    {
        $out = ['accepted' => 0, 'duplicates' => 0, 'rejected' => [], 'scrobbled' => 0, 'too_old' => 0];
        $device = null;
        $aliases = app(IdAliases::class);
        $now = CarbonImmutable::now();

        foreach ($plays as $row) {
            $clientId = (string) ($row['client_id'] ?? '');
            $startedAt = self::time($row['started_at'] ?? null);
            $seconds = (int) ($row['played_seconds'] ?? 0);

            if (!$startedAt || $startedAt->greaterThan($now->addMinutes(self::FUTURE_SLACK_MINUTES))) {
                $out['rejected'][] = ['client_id' => $clientId, 'error' => 'bad_started_at'];

                continue;
            }

            $track = Track::query()->find($aliases->resolve('track', (int) ($row['track_id'] ?? 0)));
            if (!$track) {
                $out['rejected'][] = ['client_id' => $clientId, 'error' => 'unknown_track'];

                continue;
            }

            $device ??= self::device($target);
            $startedAt = $startedAt->utc();
            $exists = Play::query()->where('device_id', $device->id)->where('track_id', $track->id)->where('played_at', $startedAt)->exists();

            if ($exists) {
                $out['duplicates']++;

                continue;
            }

            $play = Play::create([
                'device_id' => $device->id, 'track_id' => $track->id, 'duration' => $track->duration,
                'source_type' => self::SOURCE, 'source_name' => $target->name,
                'played_at' => $startedAt, 'ended_at' => $startedAt->addSeconds($seconds),
                // like a live play: under 30 seconds is a skip
                'skipped' => $seconds < 30,
            ]);
            $out['accepted']++;

            if ($startedAt->lessThan($now->subDays(self::SCROBBLE_MAX_AGE_DAYS))) {
                $out['too_old']++;
            } elseif (!$play->skipped) {
                // The job applies the usual scrobble rule (half the track, 4 minutes at most) with played_at as the timestamp.
                ScrobbleToLastfm::dispatch($play);
                $out['scrobbled']++;
            }
        }

        return $out;
    }

    /** The target's own hidden device, made on the first play. */
    public static function device(SyncTarget $target): Device
    {
        $meta = DeviceMeta::query()->where('key', 'sync_target')->where('value', $target->id)->first();
        if ($meta && ($device = Device::find($meta->device_id))) {
            return $device;
        }

        $device = Device::create([
            'device_name' => $target->name, 'device_brand_name' => 'ReMoment', 'device_driver_name' => 'ReMoment Player',
            'device_product_type' => 'Player', 'device_driver' => PlayerDriver::class, 'ip_address' => null, 'hidden' => true,
        ]);
        PlayerDevice::setMeta($device, 'sync_target', $target->id);
        PlayerDevice::setMeta($device, 'player_kind', 'ios');

        return $device;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
