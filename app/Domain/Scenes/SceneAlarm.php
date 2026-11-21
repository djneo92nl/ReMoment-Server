<?php

namespace App\Domain\Scenes;

use App\Models\Device;

/**
 * The alarm of a scene (workstream 8.1, docs/api/scenes.md): a scene that wakes someone up. Its `alarm` is
 * `{device_id, minutes, start_volume, target_volume}`; every run of the scene (usually by its schedule) sets
 * `start_volume` before the steps (best effort: a device in standby may refuse it), runs the steps (wake the
 * device, play the playlist or station) and then ramps the volume from `start_volume` to `target_volume` over
 * `minutes` with a device timer (DeviceTimers::startRamp). The ramp ends early when someone takes over the volume
 * or the device goes to standby.
 */
final class SceneAlarm
{
    /**
     * Errors of an alarm as `alarm.field => message` (empty when valid or null).
     *
     * @return array<string, string>
     */
    public static function validate(mixed $alarm): array
    {
        if ($alarm === null) {
            return [];
        }
        if (!is_array($alarm) || array_is_list($alarm)) {
            return ['alarm' => 'An alarm is an object with device_id, minutes, start_volume and target_volume.'];
        }

        $errors = [];
        $max = (int) config('scenes.alarm.max_minutes');

        $device = $alarm['device_id'] ?? null;
        if (!is_int($device)) {
            $errors['alarm.device_id'] = 'A device is required.';
        } elseif (!Device::whereKey($device)->exists()) {
            $errors['alarm.device_id'] = 'This device does not exist.';
        }

        $minutes = $alarm['minutes'] ?? null;
        if (!is_int($minutes) || $minutes < 1 || $minutes > $max) {
            $errors['alarm.minutes'] = "Minutes are 1 to {$max}.";
        }

        $start = $alarm['start_volume'] ?? null;
        $target = $alarm['target_volume'] ?? null;
        if (!is_int($start) || $start < 0 || $start > 100) {
            $errors['alarm.start_volume'] = 'The start volume is 0 to 100.';
        }
        if (!is_int($target) || $target < 1 || $target > 100) {
            $errors['alarm.target_volume'] = 'The target volume is 1 to 100.';
        }
        if (!isset($errors['alarm.start_volume'], $errors['alarm.target_volume']) && is_int($start) && is_int($target) && $target <= $start) {
            $errors['alarm.target_volume'] = 'The target volume must be higher than the start volume.';
        }

        $unknown = array_diff(array_keys($alarm), ['device_id', 'minutes', 'start_volume', 'target_volume']);
        foreach ($unknown as $name) {
            $errors["alarm.{$name}"] = 'Unknown field.';
        }

        return $errors;
    }

    /** Only the known keys, in a fixed order. */
    public static function normalise(?array $alarm): ?array
    {
        if ($alarm === null) {
            return null;
        }

        return [
            'device_id' => $alarm['device_id'],
            'minutes' => $alarm['minutes'],
            'start_volume' => $alarm['start_volume'],
            'target_volume' => $alarm['target_volume'],
        ];
    }
}
