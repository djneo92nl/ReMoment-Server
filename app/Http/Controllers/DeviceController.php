<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::all()->sortByDesc(fn ($d) => match ($d->state) {
            \App\Domain\Device\State::Playing    => 3,
            \App\Domain\Device\State::Paused     => 2,
            \App\Domain\Device\State::Standby    => 1,
            default                              => 0,
        });

        return view('devices.index', ['devices' => $devices]);
    }

    public function create()
    {
        $driverConfig = config('devices');
        return view('devices.create', compact('driverConfig'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_name'         => ['required', 'string', 'max:255'],
            'ip_address'          => ['required', 'string', 'max:255'],
            'device_brand_name'   => ['required', 'string', 'max:255'],
            'device_product_type' => ['required', 'string', 'max:255'],
            'device_driver'       => ['required', 'string', 'max:500'],
            'device_driver_name'  => ['nullable', 'string', 'max:255'],
        ]);

        $validated['uuid'] = Str::uuid()->toString();

        $device = Device::create($validated);

        return redirect()->route('devices.show', $device)->with('success', 'Device added successfully.');
    }

    public function show(Device $device)
    {
        $device->load('meta');
        return view('devices.show', compact('device'));
    }

    public function edit(Device $device)
    {
        $driverConfig = config('devices');
        return view('devices.edit', compact('device', 'driverConfig'));
    }

    public function update(Request $request, Device $device)
    {
        $validated = $request->validate([
            'device_name'         => ['required', 'string', 'max:255'],
            'ip_address'          => ['required', 'string', 'max:255'],
            'device_brand_name'   => ['required', 'string', 'max:255'],
            'device_product_type' => ['required', 'string', 'max:255'],
            'device_driver'       => ['required', 'string', 'max:500'],
            'device_driver_name'  => ['nullable', 'string', 'max:255'],
        ]);

        $device->update($validated);

        return redirect()->route('devices.show', $device)->with('success', 'Device updated.');
    }

    public function destroy(Device $device)
    {
        $device->meta()->delete();
        $device->delete();

        return redirect()->route('devices.index')->with('success', 'Device removed.');
    }
}
