<?php

namespace App\Domain\HomeAssistant;

use App\Models\Alert;
use App\Models\Client;
use App\Models\Device;
use App\Models\Scene;
use Illuminate\Support\Facades\Log;

/**
 * Where the rest of the server tells Home Assistant something happened. Every call is a no-op while discovery is off
 * and never throws into the caller (an alert, a heartbeat or a scene run must not fail over MQTT).
 */
final class Hooks
{
    public static function alert(Alert $alert): void
    {
        self::run(fn (DiscoveryPublisher $p) => $p->event('alert', $alert->isActive() ? 'raised' : 'resolved', [
            'id' => $alert->id, 'kind' => $alert->kind, 'key' => $alert->key, 'severity' => $alert->severity,
            'title' => $alert->title, 'message' => $alert->message, 'device_id' => $alert->device_id,
        ]));
    }

    public static function scene(Scene $scene, string $status, string $source): void
    {
        self::run(fn (DiscoveryPublisher $p) => $p->event('scene_activated', 'activated', [
            'scene_id' => $scene->id, 'scene' => $scene->name, 'status' => $status, 'source' => $source,
        ]));
    }

    /** A scene was created, renamed or deleted: its button appears, changes name or goes (only while the command path is on). */
    public static function scenes(): void
    {
        if (!HomeAssistant::commandsActive()) {
            return;
        }

        self::run(fn (DiscoveryPublisher $p) => $p->publishConfigs());
    }

    /** A client was created, changed or approved: state now, the config when it is new, renamed or became approved. */
    public static function client(Client $client): void
    {
        self::run(function (DiscoveryPublisher $p) use ($client) {
            if ($client->wasRecentlyCreated && $client->status === 'pending') {
                $p->event('client_pending', 'pending', ['client_id' => $client->id, 'name' => $client->name, 'pairing_code' => $client->pairing_code]);
            }
            $p->publishHub();
            if ($client->status === 'approved') {
                if ($client->wasRecentlyCreated || $client->wasChanged(['status', 'name', 'firmware_version'])) {
                    $p->publishConfigs();
                }
                $p->publishClient($client);
            }
        });
    }

    /** A client was deleted: its entities are removed, the pending count updated. */
    public static function clientDeleted(): void
    {
        self::run(function (DiscoveryPublisher $p) {
            $p->publishConfigs();
            $p->publishHub();
        });
    }

    /** A device was added, renamed or deleted: its source sensor appears, changes name or goes. */
    public static function device(): void
    {
        self::run(fn (DiscoveryPublisher $p) => $p->publishConfigs());
    }

    private static function run(\Closure $callback): void
    {
        if (!HomeAssistant::enabled()) {
            return;
        }

        try {
            $callback(app(DiscoveryPublisher::class));
        } catch (\Throwable $e) {
            Log::warning("Home Assistant publish failed: {$e->getMessage()}");
        }
    }
}
