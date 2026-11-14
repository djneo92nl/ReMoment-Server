<?php

namespace App\Domain\HomeAssistant;

use App\Domain\Profile\Profile;
use App\Domain\System\InstalledVersion;
use App\Models\Client;
use App\Models\Device;

/**
 * Every Home Assistant entity ReMoment publishes, as discovery configs (docs/architecture/home-assistant.md).
 * Players are never listed: no media_player, no transport, volume or queue entities.
 */
final class EntityCatalog
{
    public const SERVER_ID = 'remoment_server';

    public const STATS_TOPIC = 'remoment/ha/stats';

    public const HEALTH_TOPIC = 'remoment/ha/health';

    public const HUB_TOPIC = 'remoment/ha/hub';

    public const EVENT_TOPIC = 'remoment/ha/events/';

    /** Seconds after which a sensor with no new state goes unavailable: three missed refreshes of five minutes. */
    public const EXPIRE_AFTER = 900;

    /** event entity => [name, event types] */
    public const EVENTS = [
        'alert' => ['Alert', ['raised', 'resolved']],
        'scene_activated' => ['Scene activated', ['activated']],
        'client_pending' => ['Client waiting for approval', ['pending']],
        'track_started' => ['Track started', ['started']],
        'state_changed' => ['Playback state changed', ['playing', 'paused', 'standby', 'unreachable']],
        'source_changed' => ['Source changed', ['changed']],
    ];

    /**
     * @return array<string, array{component: string, config: array<string, mixed>}> keyed by discovery topic path `component/remoment_x`
     */
    public function all(): array
    {
        $entities = [];
        $add = function (string $component, string $objectId, array $config, ?array $device = null) use (&$entities) {
            $id = "remoment_{$objectId}";
            $entities["{$component}/{$id}"] = [
                'component' => $component,
                'config' => array_merge(['unique_id' => $id, 'object_id' => $id, 'device' => $device ?? $this->serverDevice(), 'availability' => $this->availability()], $config),
            ];
        };

        foreach ($this->stats() as $id => $c) {
            $add('sensor', $id, $c);
        }
        foreach ($this->health() as $id => [$component, $c]) {
            $add($component, $id, $c);
        }
        $add('sensor', 'active_alerts', [
            'name' => 'Active alerts', 'state_topic' => 'remoment/alerts/active', 'value_template' => '{{ value_json.total }}',
            'json_attributes_topic' => 'remoment/alerts/active', 'json_attributes_template' => '{{ {"counts": value_json.counts, "kinds": value_json.kinds} | tojson }}',
            'state_class' => 'measurement', 'icon' => 'mdi:alert-circle-outline',
        ]);
        $add('sensor', 'pending_clients', [
            'name' => 'Clients waiting for approval', 'state_topic' => self::HUB_TOPIC, 'value_template' => '{{ value_json.pending_clients }}',
            'state_class' => 'measurement', 'icon' => 'mdi:cellphone-link', 'expire_after' => self::EXPIRE_AFTER,
        ]);

        foreach (Client::where('status', 'approved')->get() as $client) {
            foreach ($this->client($client) as $id => [$component, $c]) {
                $add($component, $id, $c, $this->clientDevice($client));
            }
        }
        foreach (Device::orderBy('id')->get() as $device) {
            $add('sensor', "source_{$device->id}", [
                'name' => "{$device->device_name} source", 'state_topic' => "remoment/ha/sources/{$device->id}",
                'value_template' => '{{ value_json.active | default("unknown", true) }}',
                'json_attributes_topic' => "remoment/ha/sources/{$device->id}", 'json_attributes_template' => '{{ {"sources": value_json.sources, "input": value_json.input, "device_id": value_json.device_id} | tojson }}',
                'icon' => 'mdi:import', 'expire_after' => self::EXPIRE_AFTER,
            ]);
        }
        foreach (self::EVENTS as $id => [$name, $types]) {
            $add('event', "event_{$id}", [
                'name' => $name, 'state_topic' => self::EVENT_TOPIC.$id, 'event_types' => $types,
            ]);
        }

        return $entities;
    }

    /** @return array<string, mixed> */
    public function serverDevice(): array
    {
        return array_filter([
            'identifiers' => [self::SERVER_ID], 'name' => 'ReMoment Server', 'manufacturer' => 'ReMoment', 'model' => 'ReMoment Server',
            'sw_version' => InstalledVersion::current(), 'configuration_url' => config('app.url') ?: null,
        ]);
    }

    /** @return array<string, mixed> */
    public function clientDevice(Client $client): array
    {
        return array_filter([
            'identifiers' => ["remoment_client_{$client->id}"], 'name' => $client->name ?: "Client {$client->id}", 'manufacturer' => 'ReMoment',
            'model' => 'Client device', 'sw_version' => $client->firmware_version, 'via_device' => self::SERVER_ID,
        ]);
    }

    /** @return list<array<string, string>> */
    private function availability(): array
    {
        return [['topic' => HomeAssistant::STATUS_TOPIC, 'payload_available' => 'online', 'payload_not_available' => 'offline']];
    }

    /** @return array<string, array<string, mixed>> */
    private function stats(): array
    {
        $sensor = fn (string $name, string $field, array $extra = []) => array_merge([
            'name' => $name, 'state_topic' => self::STATS_TOPIC, 'value_template' => "{{ value_json.{$field} }}", 'expire_after' => self::EXPIRE_AFTER,
        ], $extra);
        $count = ['state_class' => 'measurement'];

        $out = [
            'plays_today' => $sensor('Plays today', 'plays_today', $count + ['icon' => 'mdi:play-circle-outline']),
            'plays_7d' => $sensor('Plays, last 7 days', 'plays_7d', $count + ['icon' => 'mdi:play-circle-outline']),
            'plays_total' => $sensor('Plays, all time', 'plays_total', ['state_class' => 'total_increasing', 'icon' => 'mdi:play-circle-outline']),
            'devices_playing' => $sensor('Devices playing', 'devices_playing', $count + ['icon' => 'mdi:speaker-play']),
        ];
        if (Profile::allows('library')) {
            $out += [
                'library_artists' => $sensor('Library artists', 'library_artists', $count + ['icon' => 'mdi:account-music']),
                'library_albums' => $sensor('Library albums', 'library_albums', $count + ['icon' => 'mdi:album']),
                'library_tracks' => $sensor('Library tracks', 'library_tracks', $count + ['icon' => 'mdi:music-note']),
                'favorites' => $sensor('Favorites', 'favorites', $count + ['icon' => 'mdi:heart']),
            ];
            foreach (['artist' => 'Top artist, 30 days', 'album' => 'Top album, 30 days', 'track' => 'Top track, 30 days'] as $key => $name) {
                $out["top_{$key}_30d"] = $sensor($name, "top_{$key}_30d", [
                    'json_attributes_topic' => self::STATS_TOPIC,
                    'json_attributes_template' => "{{ {'top': value_json.top_{$key}s_30d} | tojson }}",
                    'icon' => 'mdi:trophy-outline',
                ]);
            }
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    private function health(): array
    {
        $problem = fn (string $name, string $field, array $extra = []) => ['binary_sensor', array_merge([
            'name' => $name, 'state_topic' => self::HEALTH_TOPIC, 'value_template' => "{{ 'ON' if value_json.{$field} else 'OFF' }}",
            'payload_on' => 'ON', 'payload_off' => 'OFF', 'device_class' => 'problem', 'expire_after' => self::EXPIRE_AFTER,
        ], $extra)];

        $out = [
            'health_scheduler' => $problem('Scheduler problem', 'scheduler'),
            'health_queue' => $problem('Queue problem', 'queue'),
            'health_services' => $problem('External services problem', 'services', [
                'json_attributes_topic' => self::HEALTH_TOPIC, 'json_attributes_template' => '{{ {"failing": value_json.failing_services} | tojson }}',
            ]),
        ];
        if (Profile::allows('enrichment')) {
            $out['enrichment_backlog'] = ['sensor', [
                'name' => 'Enrichment backlog', 'state_topic' => self::HEALTH_TOPIC, 'value_template' => '{{ value_json.enrichment_backlog }}',
                'state_class' => 'measurement', 'entity_category' => 'diagnostic', 'expire_after' => self::EXPIRE_AFTER,
            ]];
        }
        if (Profile::allows('analysis')) {
            $out['analysis_backlog'] = ['sensor', [
                'name' => 'Analysis backlog', 'state_topic' => self::HEALTH_TOPIC, 'value_template' => '{{ value_json.analysis_backlog }}',
                'state_class' => 'measurement', 'entity_category' => 'diagnostic', 'expire_after' => self::EXPIRE_AFTER,
            ]];
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    private function client(Client $client): array
    {
        $topic = self::clientTopic($client->id);
        $base = ['state_topic' => $topic, 'expire_after' => self::EXPIRE_AFTER];
        $diag = $base + ['entity_category' => 'diagnostic'];

        return [
            "client_{$client->id}_online" => ['binary_sensor', $base + [
                'name' => 'Online', 'value_template' => "{{ 'ON' if value_json.online else 'OFF' }}", 'payload_on' => 'ON', 'payload_off' => 'OFF', 'device_class' => 'connectivity',
            ]],
            "client_{$client->id}_battery" => ['sensor', $base + [
                'name' => 'Battery', 'value_template' => '{{ value_json.battery if value_json.battery is not none else none }}', 'device_class' => 'battery', 'unit_of_measurement' => '%', 'state_class' => 'measurement',
            ]],
            "client_{$client->id}_charging" => ['binary_sensor', $base + [
                'name' => 'Charging', 'value_template' => "{{ 'ON' if value_json.charging else 'OFF' }}", 'payload_on' => 'ON', 'payload_off' => 'OFF', 'device_class' => 'battery_charging',
            ]],
            "client_{$client->id}_firmware" => ['sensor', $diag + ['name' => 'Firmware', 'value_template' => '{{ value_json.firmware }}']],
            "client_{$client->id}_build" => ['sensor', $diag + ['name' => 'Build', 'value_template' => '{{ value_json.build }}']],
            "client_{$client->id}_ip" => ['sensor', $diag + ['name' => 'IP address', 'value_template' => '{{ value_json.ip }}']],
        ];
    }

    public static function clientTopic(int $id): string
    {
        return "remoment/ha/client/{$id}";
    }
}
