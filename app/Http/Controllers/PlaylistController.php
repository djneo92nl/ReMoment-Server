<?php

namespace App\Http\Controllers;

use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Http\Controllers\Concerns\FlashesLibraryPlayback;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Playlist;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    use FlashesLibraryPlayback;

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

        $smart = $request->boolean('smart');

        $playlist = Playlist::create([
            'name' => $validated['name'],
            'source' => $smart ? 'smart' : 'local',
            'rules' => $smart ? SmartPlaylistBuilder::defaults() : null,
        ]);

        if ($smart) {
            SmartPlaylistBuilder::refresh($playlist);
        }

        return redirect()->route('playlists.show', $playlist)->with('success', 'Playlist created.');
    }

    public function destroy(Playlist $playlist)
    {
        if (!$playlist->isDeletable()) {
            return back()->with('error', 'Only playlists made here can be deleted.');
        }

        $playlist->delete();

        return redirect()->route('playlists.index')->with('success', 'Playlist deleted.');
    }

    public function show(Playlist $playlist)
    {
        $playlist->load(['tracks.artist', 'tracks.album']);

        $playableDevices = $playlist->isEditable() || $playlist->isSmart() ? $this->libraryCapableDevices() : collect();
        $spotifyDevices = $playlist->source === 'spotify' ? $this->spotifyConnectMappedDevices() : collect();

        return view('playlists.show', compact('playlist', 'playableDevices', 'spotifyDevices'));
    }

    public function play(Playlist $playlist, Device $device, LibraryPlayback $library)
    {
        return $this->flashPlayback(fn () => $library->playPlaylist($device, $playlist), $device, $playlist->name);
    }

    private function libraryCapableDevices(): \Illuminate\Support\Collection
    {
        return Device::libraryCapable();
    }

    private function spotifyConnectMappedDevices(): \Illuminate\Support\Collection
    {
        $deviceIds = DeviceMeta::where('key', 'spotify_connect_name')->pluck('device_id');

        return Device::whereIn('id', $deviceIds)->orderBy('device_name')->get();
    }
}
