<?php

namespace App\Http\Controllers;

use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Spotify\Services\PlaylistPlaybackService;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Playlist;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    public function index()
    {
        $playlists = Playlist::withCount('tracks')
            ->orderBy('name')
            ->get();

        return view('playlists.index', compact('playlists'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $playlist = Playlist::create([
            'name' => $validated['name'],
            'source' => 'local',
        ]);

        return redirect()->route('playlists.show', $playlist)->with('success', 'Playlist created.');
    }

    public function destroy(Playlist $playlist)
    {
        if (!$playlist->isEditable()) {
            return back()->with('error', 'Only local playlists can be deleted.');
        }

        $playlist->delete();

        return redirect()->route('playlists.index')->with('success', 'Playlist deleted.');
    }

    public function show(Playlist $playlist)
    {
        $playlist->load(['tracks.artist', 'tracks.album']);

        $playableDevices = $playlist->isEditable() ? $this->libraryCapableDevices() : collect();
        $spotifyDevices = $playlist->source === 'spotify' ? $this->spotifyConnectMappedDevices() : collect();

        return view('playlists.show', compact('playlist', 'playableDevices', 'spotifyDevices'));
    }

    public function play(Playlist $playlist, Device $device)
    {
        try {
            if ($playlist->source === 'spotify') {
                app(PlaylistPlaybackService::class)->playOnDevice($playlist, $device);
            } else {
                $driver = $device->driver;

                if (!($driver instanceof LibraryPlaybackInterface)) {
                    return back()->with('error', "{$device->device_name} does not support library playback.");
                }

                $driver->playLibraryPlaylist($playlist);
            }
        } catch (\Throwable $e) {
            return back()->with('error', "Could not play \"{$playlist->name}\" on {$device->device_name}: {$e->getMessage()}");
        }

        return back()->with('success', "Playing \"{$playlist->name}\" on {$device->device_name}.");
    }

    private function libraryCapableDevices(): \Illuminate\Support\Collection
    {
        return Device::all()->filter(function (Device $device) {
            try {
                return $device->driver instanceof LibraryPlaybackInterface;
            } catch (\Throwable) {
                return false;
            }
        })->values();
    }

    private function spotifyConnectMappedDevices(): \Illuminate\Support\Collection
    {
        $deviceIds = DeviceMeta::where('key', 'spotify_connect_name')->pluck('device_id');

        return Device::whereIn('id', $deviceIds)->orderBy('device_name')->get();
    }
}
