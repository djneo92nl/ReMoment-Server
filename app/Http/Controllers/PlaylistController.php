<?php

namespace App\Http\Controllers;

use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\PlaylistEditor;
use App\Http\Controllers\Concerns\FlashesLibraryPlayback;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Media\Album;
use App\Models\Media\Playlist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function store(Request $request, PlaylistEditor $editor)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $playlist = $editor->create($validated['name'], $request->boolean('smart'));

        // "New playlist" in the add-to-playlist modal: fill it with what was being added, stay on the page.
        $ids = $this->tracksToAdd($request);
        if ($ids !== []) {
            $editor->addTracks($playlist, $ids);

            return back()->with('success', 'Created "'.$playlist->name.'" with '.count($ids).' '.Str::plural('track', count($ids)).'.');
        }

        return redirect()->route('playlists.show', $playlist)->with('success', 'Playlist created.');
    }

    /** Adds one track (`track_id`) or an album's tracks of one source (`album_id` + `source`, in album order) to a playlist made here. */
    public function addTracks(Request $request, Playlist $playlist, PlaylistEditor $editor)
    {
        if (!$playlist->isEditable()) {
            return back()->with('error', 'Tracks can only be added to playlists made here.');
        }

        $ids = $this->tracksToAdd($request);
        if ($ids === []) {
            return back()->with('error', 'There are no tracks to add.');
        }

        $added = $editor->addTracks($playlist, $ids);

        return back()->with('success', $added > 0
            ? "Added {$added} ".Str::plural('track', $added)." to \"{$playlist->name}\"."
            : "Already in \"{$playlist->name}\".");
    }

    /**
     * Track ids from `track_id`, or from `album_id` limited to `source` (files|dlna|spotify), in album order.
     *
     * @return list<int>
     */
    private function tracksToAdd(Request $request): array
    {
        app(\App\Domain\Library\IdAliases::class)->resolveInput($request, ['track_id' => 'track', 'album_id' => 'album']);
        $data = $request->validate([
            'track_id' => ['nullable', 'integer', 'exists:tracks,id'],
            'album_id' => ['nullable', 'integer', 'exists:albums,id'],
            'source' => ['nullable', 'in:files,dlna,spotify'],
        ]);

        if (!empty($data['track_id'])) {
            return [(int) $data['track_id']];
        }
        if (empty($data['album_id'])) {
            return [];
        }

        $tracks = LibraryPlayback::albumTracks(Album::findOrFail($data['album_id']));
        if (!empty($data['source'])) {
            $tracks = $tracks->filter(fn ($t) => in_array($data['source'], $t->sources(), true));
        }

        return $tracks->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function destroy(Playlist $playlist, PlaylistEditor $editor)
    {
        if (!$editor->delete($playlist)) {
            return back()->with('error', 'Only playlists made here can be deleted.');
        }

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
