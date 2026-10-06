<?php

namespace App\Http\Controllers;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackFailedException;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Jobs\ImportSpotifyAlbumById;
use App\Models\Device;
use App\Models\Media\Artist;
use App\Models\Play;
use App\Services\SpotifyTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArtistController extends Controller
{
    public function index(Request $request)
    {
        $scope = LeadingSource::forRequest($request);
        $hidden = LeadingSource::hidden($scope);

        $artists = LibrarySources::artists(Artist::query(), $hidden)
            ->whereHas('albums')
            ->withCount('plays')
            ->with(['albums' => fn ($q) => LibrarySources::albums($q, $hidden)->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->orderByDesc('plays_count')
            ->paginate(50)
            ->withQueryString();

        return view('artists.index', compact('artists', 'scope'));
    }

    public function show(Artist $artist)
    {
        $artist->load(['albums.tracks.metadata' => fn ($q) => $q->where('key', 'dlna_url'), 'tracks.album']);

        $playableDevices = Device::libraryCapable();
        $spotifyConnected = app(SpotifyTokenService::class)->isConnected();

        $totalPlays = $artist->plays()->count();

        $totalSeconds = Play::whereHas('track', fn ($q) => $q->where('artist_id', $artist->id))
            ->whereNotNull('ended_at')
            ->get(['played_at', 'ended_at'])
            ->sum(fn ($p) => $p->played_at->diffInSeconds($p->ended_at));

        $topTracks = $artist->tracks()
            ->whereHas('plays')
            ->withCount('plays')
            ->orderByDesc('plays_count')
            ->limit(10)
            ->get();

        $recentPlays = Play::whereHas('track', fn ($q) => $q->where('artist_id', $artist->id))
            ->with(['track.album', 'device', 'radioStation'])
            ->orderByDesc('played_at')
            ->limit(20)
            ->get();

        return view('artists.show', compact(
            'artist',
            'totalPlays',
            'totalSeconds',
            'topTracks',
            'recentPlays',
            'playableDevices',
            'spotifyConnected',
        ));
    }

    public function play(Request $request, Artist $artist, Device $device, LibraryPlayback $library)
    {
        try {
            $result = $library->playArtist($device, $artist, $request->boolean('shuffle'));
        } catch (NotPlayableException) {
            return back()->with('error', "\"{$artist->name}\" has no tracks {$device->device_name} can play.");
        } catch (PlaybackFailedException $e) {
            return back()->with('error', "Could not play \"{$artist->name}\" on {$device->device_name}: {$e->getMessage()}");
        }

        $message = $result->playable < $result->total
            ? "Playing {$result->playable} of {$result->total} tracks by \"{$artist->name}\" on {$device->device_name}."
            : "Playing \"{$artist->name}\" on {$device->device_name}.";

        return back()->with('success', $message);
    }

    /** Queues an import of all the artist's Spotify albums and singles, whatever source the artist came from. */
    public function fill(Artist $artist, SpotifyTokenService $spotify, SpotifyLibraryImporter $importer)
    {
        if (!$spotify->isConnected()) {
            return back()->with('error', 'Connect Spotify first.');
        }

        try {
            $ids = $importer->artistAlbumIds($artist);
        } catch (\Throwable $e) {
            return back()->with('error', "Could not look up \"{$artist->name}\" on Spotify: {$e->getMessage()}");
        }

        if ($ids === []) {
            return back()->with('error', "Could not find \"{$artist->name}\" on Spotify.");
        }

        foreach ($ids as $id) {
            ImportSpotifyAlbumById::dispatch($id);
        }

        return back()->with('success', 'Importing '.count($ids).' '.Str::plural('album', count($ids))." by \"{$artist->name}\" from Spotify; they appear here as the queue works through them.");
    }

    public function favorite(Artist $artist)
    {
        $artist->update(['favorited_at' => $artist->favorited_at ? null : now()]);

        return back();
    }
}
