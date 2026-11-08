<?php

namespace App\Livewire\Developer;

use App\Domain\Developer\CallTimings;
use App\Domain\Device\Cache\Battery;
use App\Domain\Device\Cache\Modes;
use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceCapabilities;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use Livewire\Component;

/** /settings/developer: what the server holds for a device, and raw driver calls with the exception they throw. */
class DeviceInspector extends Component
{
    private const CALLS = ['play', 'pause', 'stop', 'next', 'previous', 'volume_up', 'volume_down'];

    public ?int $deviceId = null;

    /** @var array{ok: bool, message: string}|null */
    public ?array $result = null;

    public function call(string $action): void
    {
        $device = $this->deviceId ? Device::find($this->deviceId) : null;

        if (!$device || !in_array($action, self::CALLS, true)) {
            $this->result = ['ok' => false, 'message' => 'Choose a device and a known action.'];

            return;
        }

        $started = microtime(true);

        try {
            $driver = $device->driver;

            match ($action) {
                'volume_up' => $this->expect($driver, VolumeControlInterface::class)->incrementVolume(),
                'volume_down' => $this->expect($driver, VolumeControlInterface::class)->decrementVolume(),
                default => $this->expect($driver, MediaControlsInterface::class)->{$action}(),
            };

            $ms = (int) round((microtime(true) - $started) * 1000);
            CallTimings::record($device->id, $action, $ms, true);
            $this->result = ['ok' => true, 'message' => "{$action} returned after {$ms} ms."];
        } catch (\Throwable $e) {
            CallTimings::record($device->id, $action, (int) round((microtime(true) - $started) * 1000), false);
            $this->result = ['ok' => false, 'message' => get_class($e).': '.$e->getMessage()];
        }
    }

    private function expect(object $driver, string $contract): object
    {
        return $driver instanceof $contract ? $driver : throw new \RuntimeException(class_basename($driver).' does not implement '.class_basename($contract).'.');
    }

    public function render()
    {
        $device = $this->deviceId ? Device::with('meta')->find($this->deviceId) : null;
        $snapshot = null;
        $timings = $device ? CallTimings::recent($device->id) : [];

        if ($device) {
            $snapshot = [
                'driver' => $device->device_driver,
                'ip' => $device->ip_address,
                'state' => DeviceCache::getState($device->id)?->value,
                'last_seen' => (string) DeviceCache::getLastSeen($device->id),
                'listener_running' => DeviceCache::isListenerRunning($device->id),
                'capabilities' => DeviceCapabilities::for($device),
                'volume' => Volume::getVolume($device->id),
                'modes' => Modes::get($device->id),
                'battery' => Battery::get($device->id),
                'now_playing' => DeviceCache::getNowPlaying($device->id)?->toArray(),
                'meta' => $device->meta->pluck('value', 'key')->all(),
            ];
        }

        return view('livewire.developer.device-inspector', [
            'devices' => Device::orderBy('device_name')->get(),
            'snapshot' => $snapshot,
            'calls' => self::CALLS,
            'timings' => $timings,
            'averageMs' => ($ok = array_filter($timings, fn ($t) => $t['ok'] && $t['ms'] > 0)) ? (int) round(array_sum(array_column($ok, 'ms')) / count($ok)) : null,
        ]);
    }
}
