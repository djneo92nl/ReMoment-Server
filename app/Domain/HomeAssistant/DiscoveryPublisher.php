<?php

namespace App\Domain\HomeAssistant;

use App\Domain\Alerts\Alerts;
use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Device\DeviceCache;
use App\Domain\Health\ExternalServices;
use App\Domain\Library\Enrichment;
use App\Domain\Profile\Profile;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceSource;
use App\Models\Setting;
use App\Services\MqttService;
use Illuminate\Support\Facades\Log;

/**
 * Publishes the retained discovery configs and state topics (`remoment/ha/*`) of the Home Assistant entities, and
 * clears them again. The server only publishes: a restarted Home Assistant gets the configs back from the broker's
 * retained messages, and from the republish the scheduler does (configs hourly through the dedupe TTL, states every
 * five minutes). Configs are deduplicated per entity with `mqtt_published_ha_*` keys, like the other MQTT topics.
 */
final class DiscoveryPublisher
{
    /** Setting that remembers every config topic published, so removal also reaches entities that no longer exist (a deleted client). */
    public const KNOWN = 'ha_discovery_topics';

    /** A client counts as online when it sent a heartbeat this recently. */
    public const ONLINE_MINUTES = 10;

    public function __construct(private readonly MqttService $mqtt, private readonly EntityCatalog $catalog) {}

    /** Configs, then every state. `$force` skips the dedupe, so a command run always sends everything. */
    public function publishAll(bool $force = false): int
    {
        $count = $this->publishConfigs($force);
        $this->mqtt->publish(HomeAssistant::STATUS_TOPIC, 'online', retain: true);
        $this->publishStates();

        return $count;
    }

    public function publishConfigs(bool $force = false): int
    {
        $prefix = HomeAssistant::prefix();
        $entities = $this->catalog->all();

        foreach ($entities as $path => $entity) {
            $topic = "{$prefix}/{$path}/config";
            $payload = json_encode($entity['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($force) {
                $this->mqtt->publish($topic, $payload, retain: true);
            } else {
                $this->mqtt->publishIfChanged($topic, $payload, 'mqtt_published_ha_'.md5($topic));
            }
        }

        // Entities that were published before and are gone now (a deleted client or device): an empty retained config removes them.
        $known = $this->known();
        $current = array_map(fn ($path) => "{$prefix}/{$path}/config", array_keys($entities));
        foreach (array_diff($known, $current) as $stale) {
            $this->mqtt->publish($stale, '', retain: true);
            \Illuminate\Support\Facades\Cache::forget('mqtt_published_ha_'.md5($stale));
        }
        Setting::set(self::KNOWN, json_encode($current));

        return count($entities);
    }

    /** Clears every config this server ever published, marks the entities offline, and forgets the list. */
    public function remove(): int
    {
        $prefix = HomeAssistant::prefix();
        $topics = array_values(array_unique([
            ...$this->known(),
            ...array_map(fn ($path) => "{$prefix}/{$path}/config", array_keys($this->catalog->all())),
        ]));

        foreach ($topics as $topic) {
            $this->mqtt->publish($topic, '', retain: true);
            \Illuminate\Support\Facades\Cache::forget('mqtt_published_ha_'.md5($topic));
        }
        $this->mqtt->publish(HomeAssistant::STATUS_TOPIC, 'offline', retain: true);
        Setting::forget(self::KNOWN);

        return count($topics);
    }

    public function publishStates(): void
    {
        $snapshot = new StatsSnapshot;
        $this->mqtt->publish(EntityCatalog::STATS_TOPIC, json_encode($snapshot->stats()), retain: true);
        $this->mqtt->publish(EntityCatalog::HEALTH_TOPIC, json_encode($this->health()), retain: true);
        $this->mqtt->publish(EntityCatalog::HUB_TOPIC, json_encode(['pending_clients' => Client::where('status', 'pending')->count()]), retain: true);

        Client::where('status', 'approved')->get()->each(fn (Client $c) => $this->publishClient($c));
        Device::all()->each(fn (Device $d) => $this->publishSources($d));
    }

    public function publishHub(): void
    {
        $this->mqtt->publishIfChanged(EntityCatalog::HUB_TOPIC, json_encode(['pending_clients' => Client::where('status', 'pending')->count()]), 'mqtt_published_ha_hub');
    }

    public function publishClient(Client $client): void
    {
        $this->mqtt->publishIfChanged(EntityCatalog::clientTopic($client->id), json_encode([
            'online' => $client->last_seen_at !== null && $client->last_seen_at->gte(now()->subMinutes(self::ONLINE_MINUTES)),
            'battery' => $client->battery_percent, 'charging' => (bool) $client->battery_charging,
            'firmware' => $client->firmware_version, 'build' => $client->build_number, 'ip' => $client->ip_address,
        ]), "mqtt_published_ha_client_{$client->id}");
    }

    /**
     * One sensor per device: state is the source that plays (from now playing, never `in_use`, which ASE reports true for
     * nearly every source), attributes the sources the admin has not hidden.
     */
    public function publishSources(Device $device): void
    {
        $source = DeviceCache::getNowPlaying($device->id)?->source;
        $this->mqtt->publishIfChanged("remoment/ha/sources/{$device->id}", json_encode([
            'device_id' => $device->id,
            'active' => $source?->name,
            'input' => $source?->input(),
            'sources' => DeviceSource::where('device_id', $device->id)->where('hidden', false)->orderBy('sort_order')->pluck('friendly_name')->values()->all(),
        ]), "mqtt_published_ha_sources_{$device->id}");
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $failing = collect((new ExternalServices)->all())->filter(fn ($r) => ($r['status'] ?? '') === 'error')->keys()->values()->all();
        $out = [
            'scheduler' => Alerts::isActive('scheduler.silent'),
            'queue' => \App\Models\Alert::active()->where('kind', 'queue.stuck')->exists(),
            'services' => $failing !== [],
            'failing_services' => $failing,
        ];
        if (Profile::allows('enrichment')) {
            $out['enrichment_backlog'] = Enrichment::backlog()->count();
        }
        if (Profile::allows('analysis')) {
            $out['analysis_backlog'] = AudioAnalysis::enabled() ? AudioAnalysis::pending(1000)->count() : 0;
        }

        return $out;
    }

    /** Non-retained event for an `event` entity (docs: the topics under `remoment/ha/events/`); never throws, silent while discovery is off. */
    public function event(string $entity, string $type, array $attributes = []): void
    {
        if (!HomeAssistant::enabled() || !isset(EntityCatalog::EVENTS[$entity])) {
            return;
        }

        try {
            $this->mqtt->publish(EntityCatalog::EVENT_TOPIC.$entity, json_encode(['event_type' => $type] + $attributes, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::warning("Home Assistant event {$entity} failed: {$e->getMessage()}");
        }
    }

    /** @return list<string> */
    private function known(): array
    {
        $decoded = json_decode((string) Setting::get(self::KNOWN, '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
