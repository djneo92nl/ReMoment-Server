<?php

namespace App\Domain\Developer;

use App\Models\Device;
use App\Models\DeviceMeta;

/**
 * What a player was found to decode, from the capability probe of /settings/developer: a ladder rung is `ok` or
 * `broken`. Kept as device meta `capability_probe` (JSON), so it survives and a client or a later feature can read it.
 */
final class CapabilityProfile
{
    public const KEY = 'capability_probe';

    /** @return array<string, string> profile name => ok|broken */
    public static function get(Device $device): array
    {
        $raw = DeviceMeta::where('device_id', $device->id)->where('key', self::KEY)->value('value');

        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    public static function mark(Device $device, string $profile, ?string $verdict): void
    {
        $all = self::get($device);

        if ($verdict === null) {
            unset($all[$profile]);
        } elseif (in_array($verdict, ['ok', 'broken'], true)) {
            $all[$profile] = $verdict;
        }

        $all === []
            ? DeviceMeta::where('device_id', $device->id)->where('key', self::KEY)->delete()
            : DeviceMeta::updateOrCreate(['device_id' => $device->id, 'key' => self::KEY], ['value' => json_encode($all)]);
    }

    public static function clear(Device $device): void
    {
        DeviceMeta::where('device_id', $device->id)->where('key', self::KEY)->delete();
    }

    /**
     * Per ladder group: the rungs that worked, the ones that did not and the highest sample rate that worked.
     *
     * @return array<string, array{ok: string[], broken: string[], max_rate: ?int}>
     */
    public static function summary(Device $device): array
    {
        $verdicts = self::get($device);
        $summary = [];

        foreach (QualityPlayer::ladder() as $rung) {
            $verdict = $verdicts[$rung['name']] ?? null;

            if ($verdict === null) {
                continue;
            }

            $group = &$summary[$rung['group']];
            $group ??= ['ok' => [], 'broken' => [], 'max_rate' => null];
            $group[$verdict][] = $rung['label'];

            if ($verdict === 'ok') {
                $group['max_rate'] = max($group['max_rate'] ?? 0, $rung['rate']);
            }

            unset($group);
        }

        return $summary;
    }
}
