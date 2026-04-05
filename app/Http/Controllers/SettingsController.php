<?php

namespace App\Http\Controllers;

use App\Domain\Device\DeviceCache;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $userCount = User::count();
        $deviceCount = Device::count();
        return view('settings.index', compact('userCount', 'deviceCount'));
    }

    public function users()
    {
        $users = User::orderBy('name')->get();
        return view('settings.users', compact('users'));
    }

    public function destroyUser(User $user)
    {
        $user->delete();
        return redirect()->route('settings.users')->with('success', 'User removed.');
    }

    public function listeners()
    {
        $devices = Device::orderBy('device_name')->get()->map(function (Device $device) {
            return [
                'device'          => $device,
                'listener_running' => DeviceCache::isListenerRunning($device->id),
                'can_start'       => $device->device_driver_name === 'ASE',
            ];
        });

        return view('settings.listeners', compact('devices'));
    }

    public function startListener(Device $device)
    {
        if ($device->device_driver_name !== 'ASE') {
            return back()->with('error', 'Listeners can only be started for ASE devices.');
        }

        if (DeviceCache::isListenerRunning($device->id)) {
            return back()->with('error', "Listener for {$device->device_name} is already running.");
        }

        $cmd = 'php '.base_path('artisan')." device-ase:listen-single '{$device->id}' > /dev/null 2>&1 &";
        shell_exec($cmd);

        return back()->with('success', "Listener started for {$device->device_name}.");
    }

    public function startAllListeners()
    {
        $devices = Device::where('device_driver_name', 'ASE')->get();
        $started = 0;

        foreach ($devices as $device) {
            if (!DeviceCache::isListenerRunning($device->id)) {
                $cmd = 'php '.base_path('artisan')." device-ase:listen-single '{$device->id}' > /dev/null 2>&1 &";
                shell_exec($cmd);
                $started++;
            }
        }

        $message = $started > 0
            ? "Started {$started} " . ($started === 1 ? 'listener' : 'listeners') . '.'
            : 'All ASE listeners are already running.';

        return back()->with('success', $message);
    }
}
