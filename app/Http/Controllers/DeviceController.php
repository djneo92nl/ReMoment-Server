<?php

namespace App\Http\Controllers;

use App\Domain\Device\DeviceCache;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\Play;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::all()->sortByDesc(fn ($d) => match ($d->state) {
            \App\Domain\Device\State::Playing => 3,
            \App\Domain\Device\State::Paused => 2,
            \App\Domain\Device\State::Standby => 1,
            default => 0,
        });

        return view('devices.index', ['devices' => $devices]);
    }

    public function create()
    {
        $driverConfig = collect(config('devices'))->except('discoverers')->all();

        return view('devices.create', compact('driverConfig'));
    }

    public function store(Request $request)
    {
        $driverConfig = config('devices');
        $brand = $request->input('device_brand_name', '');
        $product = $request->input('device_product_type', '');
        $isVirtual = ($driverConfig[$brand][$product]['virtual'] ?? false) === true;

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
            'ip_address' => $isVirtual ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'device_brand_name' => ['required', 'string', 'max:255'],
            'device_product_type' => ['required', 'string', 'max:255'],
            'device_driver' => ['required', 'string', 'max:500'],
            'device_driver_name' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['uuid'] = Str::uuid()->toString();

        $device = Device::create($validated);

        return redirect()->route('devices.show', $device)->with('success', 'Device added successfully.');
    }

    public function show(Device $device)
    {
        $device->load('meta');

        $capabilities = [];
        $volume = null;
        try {
            $driver = $device->driver;
            if ($driver instanceof MediaControlsInterface) {
                $capabilities[] = 'media_controls';
            }
            if ($driver instanceof VolumeControlInterface) {
                $capabilities[] = 'volume_control';
                $volume = $driver->getVolume();
            }
            if (method_exists($driver, 'getSources')) {
                $capabilities[] = 'source_control';
            }
            if (method_exists($driver, 'standby')) {
                $capabilities[] = 'standby';
            }
            if (method_exists($driver, 'getSpeakerGroups')) {
                $capabilities[] = 'speaker_groups';
            }
            if (method_exists($driver, 'getSoundModes')) {
                $capabilities[] = 'sound_modes';
            }
        } catch (\Throwable) {
            // driver unavailable — show what we can
        }

        $listenerRunning = DeviceCache::isListenerRunning($device->id);
        $mqttTopic = "remoment/player/{$device->id}";

        $totalSeconds = Play::where('device_id', $device->id)
            ->whereNotNull('ended_at')
            ->get(['played_at', 'ended_at'])
            ->sum(fn ($p) => $p->played_at->diffInSeconds($p->ended_at));

        $stats = [
            'total_plays' => Play::where('device_id', $device->id)->count(),
            'total_seconds' => (int) $totalSeconds,
            'top_artist' => Play::where('device_id', $device->id)
                ->whereNotNull('track_id')
                ->join('tracks', 'plays.track_id', '=', 'tracks.id')
                ->join('artists', 'tracks.artist_id', '=', 'artists.id')
                ->selectRaw('artists.id, artists.name, COUNT(*) as play_count')
                ->groupBy('artists.id', 'artists.name')
                ->orderByDesc('play_count')
                ->first(),
        ];

        return view('devices.show', compact(
            'device',
            'capabilities',
            'volume',
            'listenerRunning',
            'mqttTopic',
            'stats',
        ));
    }

    public function edit(Device $device)
    {
        $driverConfig = collect(config('devices'))->except('discoverers')->all();

        return view('devices.edit', compact('device', 'driverConfig'));
    }

    public function update(Request $request, Device $device)
    {
        $driverConfig = config('devices');
        $brand = $request->input('device_brand_name', '');
        $product = $request->input('device_product_type', '');
        $isVirtual = ($driverConfig[$brand][$product]['virtual'] ?? false) === true;

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
            'ip_address' => $isVirtual ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'device_brand_name' => ['required', 'string', 'max:255'],
            'device_product_type' => ['required', 'string', 'max:255'],
            'device_driver' => ['required', 'string', 'max:500'],
            'device_driver_name' => ['nullable', 'string', 'max:255'],
        ]);

        $device->update($validated);

        return redirect()->route('devices.show', $device)->with('success', 'Device updated.');
    }

    public function standby(Device $device)
    {
        try {
            $driver = $device->driver;
            if (method_exists($driver, 'standby')) {
                $driver->standby();
            }
        } catch (\Throwable) {
            // silently ignore if device is unreachable
        }

        return redirect()->route('devices.show', $device);
    }

    public function destroy(Device $device)
    {
        $device->meta()->delete();
        $device->delete();

        return redirect()->route('devices.index')->with('success', 'Device removed.');
    }
}
