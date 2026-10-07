<?php

namespace App\Http\Controllers;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Artwork\RadioStationArtwork;
use App\Integrations\Contracts\RadioControlInterface;
use App\Models\Device;
use App\Models\RadioStation;
use Illuminate\Http\Request;

class RadioStationController extends Controller
{
    public function index()
    {
        $stations = RadioStation::query()
            ->with('meta')
            ->withCount('plays')
            ->orderByRaw('favorited_at IS NULL')
            ->orderByDesc('favorited_at')
            ->orderBy('name')
            ->get();

        $artwork = $stations->mapWithKeys(fn (RadioStation $station) => [
            $station->id => LibraryItemArtwork::webUrl(RadioStationArtwork::resolve($station)['proxy_120'] ?? null),
        ]);

        $devices = $this->radioCapableDevices();

        return view('radio.index', compact('stations', 'devices', 'artwork'));
    }

    public function create()
    {
        return view('radio.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $station = RadioStation::create($validated);

        foreach ($request->input('identifiers', []) as $platform => $identifier) {
            $identifier = trim((string) $identifier);
            if ($identifier !== '') {
                $station->setMeta($platform, $identifier);
            }
        }

        return redirect()->route('radio.index')->with('success', 'Radio station added.');
    }

    public function edit(RadioStation $radio)
    {
        $radio->load('meta');

        return view('radio.edit', compact('radio'));
    }

    public function update(Request $request, RadioStation $radio)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $radio->update($validated);

        foreach ($request->input('identifiers', []) as $platform => $identifier) {
            $identifier = trim((string) $identifier);
            if ($identifier !== '') {
                $radio->setMeta($platform, $identifier);
            } else {
                $radio->meta()->where('key', $platform)->delete();
                $radio->unsetRelation('meta');
            }
        }

        return redirect()->route('radio.index')->with('success', 'Radio station updated.');
    }

    public function favorite(RadioStation $radio)
    {
        $radio->update(['favorited_at' => $radio->favorited_at ? null : now()]);

        return back();
    }

    public function destroy(RadioStation $radio)
    {
        $radio->delete();

        return redirect()->route('radio.index')->with('success', 'Radio station removed.');
    }

    public function play(RadioStation $radio, Device $device)
    {
        try {
            $driver = $device->driver;

            if (!($driver instanceof RadioControlInterface)) {
                return back()->with('error', "{$device->device_name} does not support radio playback.");
            }

            if (!$driver->canPlayRadioStation($radio)) {
                return back()->with('error', "No {$driver->radioPlatform()} identifier set for {$radio->name}.");
            }

            $driver->playRadioStation($radio);
        } catch (\Throwable $e) {
            return back()->with('error', "Could not reach {$device->device_name}: {$e->getMessage()}");
        }

        return back()->with('success', "Playing {$radio->name} on {$device->device_name}.");
    }

    private function radioCapableDevices(): \Illuminate\Support\Collection
    {
        return Device::all()->filter(
            fn (Device $device) => is_a($device->device_driver, RadioControlInterface::class, true)
        )->values();
    }
}
