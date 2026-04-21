<?php

namespace App\Http\Controllers;

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
            ->orderBy('name')
            ->get();

        $devices = $this->radioCapableDevices();

        return view('radio.index', compact('stations', 'devices'));
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
                $radio->unsetRelation('identifiers');
            }
        }

        return redirect()->route('radio.index')->with('success', 'Radio station updated.');
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
        return Device::all()->filter(function (Device $device) {
            try {
                return $device->driver instanceof RadioControlInterface;
            } catch (\Throwable) {
                return false;
            }
        })->values();
    }
}
