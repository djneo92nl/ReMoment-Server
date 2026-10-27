<?php

namespace App\Domain\Alerts;

use App\Models\Alert;
use App\Services\MqttService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The one place that raises and resolves alerts (docs/architecture/alerts.md). A condition has a (kind, key)
 * and at most one active alert; raising it again updates that alert instead of repeating it. Everything else
 * (MQTT, the API, the health banner) reads from here. A failure inside never reaches the caller.
 */
final class Alerts
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    public const SUMMARY_TOPIC = 'remoment/alerts/active';

    private const SUMMARY_DEDUPE_KEY = 'mqtt_published_alerts_active';

    /** Resolved alerts kept by prune(), next to every active one. */
    public const KEEP_RESOLVED = 100;

    /**
     * Raises the alert, or refreshes the active one. Publishes only when it is new or its severity or message changed.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function raise(string $kind, string $key, string $severity, string $title, string $message, ?int $deviceId = null, ?array $data = null): ?Alert
    {
        try {
            return self::doRaise($kind, $key, $severity, $title, $message, $deviceId, $data);
        } catch (\Throwable $e) {
            Log::warning("Alert {$kind}/{$key} could not be raised: {$e->getMessage()}");

            return null;
        }
    }

    /** Closes the active alert of this condition; false when there was none. */
    public static function resolve(string $kind, string $key = ''): bool
    {
        try {
            $alert = Alert::where('active_key', self::activeKey($kind, $key))->first();
            if (!$alert) {
                return false;
            }

            $alert->update(['resolved_at' => now(), 'active_key' => null]);
            self::publish($alert);

            return true;
        } catch (\Throwable $e) {
            Log::warning("Alert {$kind}/{$key} could not be resolved: {$e->getMessage()}");

            return false;
        }
    }

    public static function isActive(string $kind, string $key = ''): bool
    {
        return Alert::where('active_key', self::activeKey($kind, $key))->exists();
    }

    /** @return Collection<int, Alert> newest first */
    public static function active(): Collection
    {
        return Alert::active()->orderByDesc('id')->get();
    }

    /** @return Collection<int, Alert> the last resolved ones, newest first */
    public static function recent(int $limit = self::KEEP_RESOLVED): Collection
    {
        return Alert::whereNotNull('resolved_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Alerts with an id above `$id`, newest first.
     *
     * @param  'active'|'resolved'|'all'  $state
     * @return Collection<int, Alert>
     */
    public static function since(int $id = 0, string $state = 'all', int $limit = 100): Collection
    {
        return Alert::where('id', '>', $id)
            ->when($state === 'active', fn ($q) => $q->whereNull('resolved_at'))
            ->when($state === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->orderByDesc('id')->limit($limit)->get();
    }

    public static function acknowledge(Alert $alert): Alert
    {
        if ($alert->acknowledged_at === null) {
            $alert->update(['acknowledged_at' => now()]);
        }

        return $alert;
    }

    /** Keeps every active alert and the newest KEEP_RESOLVED resolved ones. Returns how many rows went. */
    public static function prune(): int
    {
        $keepFrom = Alert::whereNotNull('resolved_at')->orderByDesc('id')->skip(self::KEEP_RESOLVED - 1)->take(1)->value('id');

        return $keepFrom === null ? 0 : Alert::whereNotNull('resolved_at')->where('id', '<', $keepFrom)->delete();
    }

    /** @return array{counts: array<string, int>, kinds: list<string>, total: int} */
    public static function summary(): array
    {
        $active = Alert::active()->get(['kind', 'severity']);
        $counts = array_fill_keys(self::SEVERITIES, 0);
        foreach ($active as $alert) {
            $counts[$alert->severity] = ($counts[$alert->severity] ?? 0) + 1;
        }

        return ['counts' => $counts, 'kinds' => $active->pluck('kind')->unique()->sort()->values()->all(), 'total' => $active->count()];
    }

    private static function doRaise(string $kind, string $key, string $severity, string $title, string $message, ?int $deviceId, ?array $data): Alert
    {
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Unknown severity {$severity}");
        }

        $existing = Alert::where('active_key', self::activeKey($kind, $key))->first();
        if ($existing) {
            return self::refresh($existing, $severity, $title, $message, $deviceId, $data);
        }

        try {
            $alert = Alert::create([
                'kind' => $kind, 'key' => $key, 'active_key' => self::activeKey($kind, $key), 'severity' => $severity,
                'device_id' => $deviceId, 'title' => $title, 'message' => $message, 'data' => $data, 'raised_at' => now(),
            ]);
        } catch (QueryException) {
            // Another process raised the same condition between our read and write: treat it as the refresh it is.
            $existing = Alert::where('active_key', self::activeKey($kind, $key))->first() ?? throw new \RuntimeException('Alert vanished');

            return self::refresh($existing, $severity, $title, $message, $deviceId, $data);
        }

        self::publish($alert);

        return $alert;
    }

    private static function refresh(Alert $alert, string $severity, string $title, string $message, ?int $deviceId, ?array $data): Alert
    {
        $changed = $alert->severity !== $severity || $alert->message !== $message;
        $alert->fill(['severity' => $severity, 'title' => $title, 'message' => $message, 'device_id' => $deviceId, 'data' => $data]);
        if ($alert->isDirty()) {
            $alert->save();
        }
        if ($changed) {
            self::publish($alert);
        }

        return $alert;
    }

    private static function activeKey(string $kind, string $key): string
    {
        return "{$kind}|{$key}";
    }

    /** `remoment/alerts/{kind}` (not retained) and the retained summary; never throws. */
    private static function publish(Alert $alert): void
    {
        try {
            $mqtt = app(MqttService::class);
            $mqtt->publish("remoment/alerts/{$alert->kind}", json_encode([
                'id' => $alert->id, 'kind' => $alert->kind, 'key' => $alert->key, 'severity' => $alert->severity,
                'title' => $alert->title, 'message' => $alert->message, 'device_id' => $alert->device_id,
                'raised_at' => $alert->raised_at->toIso8601String(), 'state' => $alert->isActive() ? 'raised' : 'resolved',
            ]));
            $mqtt->publishIfChanged(self::SUMMARY_TOPIC, json_encode(self::summary()), self::SUMMARY_DEDUPE_KEY);
        } catch (\Throwable $e) {
            Log::warning("Alert publish failed: {$e->getMessage()}");
        }
    }
}
