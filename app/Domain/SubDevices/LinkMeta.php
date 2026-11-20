<?php

namespace App\Domain\SubDevices;

/**
 * What a link's `meta` may hold (docs/architecture/sub-devices.md), checked on every save:
 *
 * - `on_activate` / `on_deactivate`: a list of actions for the sub device, run when the link becomes active or ends
 *   (`{action: power_on | power_off | source, source_id?}`; a single action object is read as a list of one);
 * - `follow` (`off` | `from_standby` | `always`) and `follow_off_after` (minutes, or null): the follow rule, step 3;
 * - `state`: what the server itself keeps on the link (`woke_host`, `woke_at`), never set from outside.
 *
 * Anything else is refused, so a typo is an error instead of a setting that silently does nothing.
 */
final class LinkMeta
{
    public const ACTIONS = ['power_on', 'power_off', 'source'];

    public const FOLLOW = ['off', 'from_standby', 'always'];

    public const MAX_ACTIONS = 5;

    public const DEFAULT_FOLLOW = 'from_standby';

    /** The settings a person may set; `state` is the server's. */
    public const SETTINGS = ['on_activate', 'on_deactivate', 'follow', 'follow_off_after'];

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>|null null for nothing set
     *
     * @throws InvalidDeviceLink
     */
    public static function normalize(?array $meta): ?array
    {
        if ($meta === null || $meta === []) {
            return null;
        }

        $unknown = array_diff(array_keys($meta), [...self::SETTINGS, 'state']);
        if ($unknown !== []) {
            throw new InvalidDeviceLink("Unknown link setting '".reset($unknown)."' (".implode(', ', self::SETTINGS).').');
        }

        $clean = [];

        foreach (['on_activate', 'on_deactivate'] as $key) {
            if (array_key_exists($key, $meta) && $meta[$key] !== null) {
                $actions = self::actions($key, $meta[$key]);
                if ($actions !== []) {
                    $clean[$key] = $actions;
                }
            }
        }

        if (array_key_exists('follow', $meta) && $meta['follow'] !== null) {
            if (!in_array($meta['follow'], self::FOLLOW, true)) {
                throw new InvalidDeviceLink('follow must be one of '.implode(', ', self::FOLLOW).'.');
            }
            $clean['follow'] = $meta['follow'];
        }

        if (array_key_exists('follow_off_after', $meta) && $meta['follow_off_after'] !== null) {
            $minutes = $meta['follow_off_after'];
            if (!is_int($minutes) && !(is_string($minutes) && ctype_digit($minutes))) {
                throw new InvalidDeviceLink('follow_off_after is a whole number of minutes, or null.');
            }
            if ((int) $minutes < 1 || (int) $minutes > 1440) {
                throw new InvalidDeviceLink('follow_off_after is between 1 and 1440 minutes.');
            }
            $clean['follow_off_after'] = (int) $minutes;
        }

        if (isset($meta['state']) && is_array($meta['state']) && $meta['state'] !== []) {
            $clean['state'] = $meta['state'];
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * The same meta with the server's `state` of $current kept: what a person edits never carries it.
     *
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>|null
     */
    public static function replace(?array $new, ?array $current): ?array
    {
        unset($new['state']);

        $meta = self::normalize($new) ?? [];

        if (isset($current['state'])) {
            $meta['state'] = $current['state'];
        }

        return $meta === [] ? null : $meta;
    }

    /** @return list<array{action: string, source_id?: string}> */
    private static function actions(string $key, mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidDeviceLink("{$key} is a list of actions.");
        }

        $list = array_is_list($value) ? $value : [$value];

        if (count($list) > self::MAX_ACTIONS) {
            throw new InvalidDeviceLink("{$key} holds at most ".self::MAX_ACTIONS.' actions.');
        }

        return array_map(function ($action) use ($key) {
            if (!is_array($action) || !in_array($action['action'] ?? null, self::ACTIONS, true)) {
                throw new InvalidDeviceLink("An action of {$key} is one of ".implode(', ', self::ACTIONS).'.');
            }

            if ($action['action'] !== 'source') {
                return ['action' => $action['action']];
            }

            $source = $action['source_id'] ?? null;
            if (!is_string($source) || trim($source) === '') {
                throw new InvalidDeviceLink("A source action of {$key} needs a source_id.");
            }

            return ['action' => 'source', 'source_id' => trim($source)];
        }, $list);
    }

    /** The follow rule of a link: its setting, else the default. */
    public static function follow(?array $meta): string
    {
        return $meta['follow'] ?? self::DEFAULT_FOLLOW;
    }
}
