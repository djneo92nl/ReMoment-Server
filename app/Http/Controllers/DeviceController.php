<?php

namespace App\Http\Controllers;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceCapabilities;
use App\Domain\Device\MultiRoomGroups;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\SpotifyUri;
use App\Http\Controllers\Concerns\FlashesLibraryPlayback;
use App\Integrations\Contracts\VolumeControlInterface;
use App\Models\Device;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    use FlashesLibraryPlayback;

    public function index(Request $request)
    {
        $showHidden = $request->boolean('hidden');

        $all = Device::all();
        $hiddenCount = $all->where('hidden', true)->count();

        // The Spotify virtual device is hidden while Spotify plays on a mapped speaker in the list; a device
        // joined to a host in the list is shown on the host's card.
        $devices = MultiRoomGroups::withoutListeners(SpotifyRouting::visible($all->filter(fn (Device $device) => !$device->hidden || $showHidden)))
            ->sortByDesc(fn ($d) => match ($d->state) {
                \App\Domain\Device\State::Playing => 3,
                \App\Domain\Device\State::Paused => 2,
                \App\Domain\Device\State::Standby => 1,
                default => 0,
            });

        return view('devices.index', ['devices' => $devices, 'hiddenCount' => $hiddenCount, 'showHidden' => $showHidden]);
    }

    public function create()
    {
        $driverConfig = collect(config('devices'))->except(['discoverers', 'listeners'])->all();

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

        $device = Device::create($validated);

        return redirect()->route('devices.show', $device)->with('success', 'Device added successfully.');
    }

    public function show(Device $device)
    {
        $device->load('meta');

        $capabilities = DeviceCapabilities::for($device);
        $volume = null;
        try {
            $driver = $device->driver;
            if ($driver instanceof VolumeControlInterface) {
                $volume = $driver->getVolume();
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
        $sources = $device->deviceSources()->orderBy('category')->orderBy('friendly_name')->get();

        $totalSeconds = Play::secondsListened(Play::where('device_id', $device->id));

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
            'sources',
        ));
    }

    public function edit(Device $device)
    {
        $driverConfig = collect(config('devices'))->except(['discoverers', 'listeners'])->all();

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

    public function playTrack(Track $track, Device $device, LibraryPlayback $library)
    {
        return $this->flashPlayback(fn () => $library->playTrack($device, $track), $device, $track->name);
    }

    /** Plays a Spotify track that isn't in the library (a "More on Spotify" row). */
    public function playSpotifyTrack(string $spotifyTrackId, Device $device, LibraryPlayback $library)
    {
        return $this->flashPlayback(fn () => $library->playSpotifyUri($device, SpotifyUri::track($spotifyTrackId)), $device, null);
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

    public function toggleHidden(Device $device)
    {
        $device->update(['hidden' => !$device->hidden]);

        return back()->with('success', $device->hidden ? "{$device->device_name} hidden." : "{$device->device_name} unhidden.");
    }

    public function destroy(Device $device)
    {
        $device->meta()->delete();
        $device->delete();

        return redirect()->route('devices.index')->with('success', 'Device removed.');
    }
}
