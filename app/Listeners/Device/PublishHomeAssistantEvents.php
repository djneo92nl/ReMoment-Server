<?php

namespace App\Listeners\Device;

use App\Domain\HomeAssistant\DiscoveryPublisher;
use App\Domain\HomeAssistant\HomeAssistant;
use App\Events\Device\DeviceStateChanged;
use App\Events\Device\NowPlayingUpdated;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Home Assistant event entities for playback (docs/architecture/home-assistant.md): a new track, a new playback state
 * and a changed source or TV input, each carrying the device id and name. Only while discovery is on. Dedupe keys
 * (`ha_*_{device}`) keep the repeated reports of a listener from firing the same event twice.
 */
class PublishHomeAssistantEvents
{
    public function __construct(private readonly DiscoveryPublisher $publisher) {}

    public function handleNowPlaying(NowPlayingUpdated $event): void
    {
        if (!HomeAssistant::enabled()) {
            return;
        }

        try {
            $device = Device::find((int) $event->deviceId);
            if (!$device) {
                return;
            }
            $base = ['device_id' => $device->id, 'device' => $device->device_name];
            $np = $event->nowPlaying;

            $track = $np->track?->id ?? $np->track?->name;
            if ($track !== null && Cache::get("ha_track_{$device->id}") !== $track) {
                Cache::put("ha_track_{$device->id}", $track, 86400);
                $this->publisher->event('track_started', 'started', $base + array_filter([
                    'track' => $np->track?->name, 'artist' => $np->track?->artist?->name, 'album' => $np->album?->name,
                ], fn ($v) => $v !== null));
            }

            $source = $np->source;
            $signature = $source ? ($source->name.'|'.($source->input() ?? '')) : null;
            if ($signature !== null && Cache::get("ha_source_{$device->id}") !== $signature) {
                $first = Cache::get("ha_source_{$device->id}") === null;
                Cache::put("ha_source_{$device->id}", $signature, 86400);
                $this->publisher->publishSources($device);
                if (!$first) {
                    $this->publisher->event('source_changed', 'changed', $base + array_filter([
                        'source' => $source->name, 'input' => $source->input(),
                    ], fn ($v) => $v !== null));
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Home Assistant playback event failed: {$e->getMessage()}");
        }
    }

    public function handleState(DeviceStateChanged $event): void
    {
        if (!HomeAssistant::enabled()) {
            return;
        }

        $device = Device::find($event->deviceId);
        if ($device) {
            $this->publisher->event('state_changed', $event->state->value, [
                'device_id' => $device->id, 'device' => $device->device_name, 'previous' => $event->previous?->value,
            ]);
        }
    }
}
