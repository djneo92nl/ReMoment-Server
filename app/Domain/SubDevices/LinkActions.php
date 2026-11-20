<?php

namespace App\Domain\SubDevices;

use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Models\DeviceLink;
use Illuminate\Support\Facades\Log;

/**
 * Runs the actions a link carries for its sub device (`meta.on_activate` when the link becomes active,
 * `meta.on_deactivate` when it ends): power on, power off, activate a source. Example: a CD changer behind a player's
 * line-in, controlled by an ESP: selecting that line-in sends the ESP its power on, leaving it the power off. An action
 * the sub device's driver cannot do is skipped and logged, a failing one does not stop the next: the host's input has
 * already changed and nothing here can undo it.
 */
final class LinkActions
{
    public const SETTINGS = ['activate' => 'on_activate', 'deactivate' => 'on_deactivate'];

    /** Whether the link has anything to run for this phase (`LinkTransitions::ACTIVATE` / `DEACTIVATE`). */
    public static function has(DeviceLink $link, string $phase): bool
    {
        return !empty($link->meta[self::SETTINGS[$phase] ?? ''] ?? []);
    }

    /** @return int how many actions succeeded */
    public static function run(DeviceLink $link, string $phase): int
    {
        $actions = $link->meta[self::SETTINGS[$phase] ?? ''] ?? [];
        $sub = $link->device;
        $done = 0;

        if ($sub === null) {
            return 0;
        }

        foreach ($actions as $action) {
            try {
                $driver = $sub->driver;

                match ($action['action']) {
                    'power_on' => $driver instanceof PowerInterface ? $driver->powerOn() : self::cannot($link, $action),
                    'power_off' => $driver instanceof PowerInterface ? $driver->standby() : self::cannot($link, $action),
                    'source' => $driver instanceof SourceActivationInterface ? $driver->activateSource((string) $action['source_id']) : self::cannot($link, $action),
                    default => self::cannot($link, $action),
                };

                $done++;
            } catch (\Throwable $e) {
                Log::warning("Link {$link->id}: {$action['action']} on device {$sub->id} failed: {$e->getMessage()}");
            }
        }

        return $done;
    }

    private static function cannot(DeviceLink $link, array $action): never
    {
        throw new \RuntimeException("device {$link->device_id} cannot do '{$action['action']}'");
    }
}
