<?php

namespace App\Http\Controllers;

use App\Domain\Artwork\SdCardExport;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibrarySettings;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\DlnaServer;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dlna\DlnaLibraryScanner;
use App\Services\Dlna\DlnaServerDiscovery;
use App\Services\LastfmSessionService;
use App\Services\SpotifyTokenService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $userCount = User::count();
        $deviceCount = Device::count();
        $spotifyConnected = app(SpotifyTokenService::class)->isConnected();
        $lastfmConnected = app(LastfmSessionService::class)->isConnected();
        $dlnaServerCount = DlnaServer::count();
        $clientCount = Client::count();
        $pendingClientCount = Client::where('status', 'pending')->count();

        return view('settings.index', compact(
            'userCount', 'deviceCount', 'spotifyConnected', 'lastfmConnected',
            'dlnaServerCount', 'clientCount', 'pendingClientCount',
        ));
    }

    public function spotify(SpotifyTokenService $spotify)
    {
        return view('settings.spotify', [
            'connected' => $spotify->isConnected(),
        ]);
    }

    public function lastfm(LastfmSessionService $lastfm)
    {
        return view('settings.lastfm', [
            'connected' => $lastfm->isConnected(),
            'username' => $lastfm->username(),
        ]);
    }

    public function library()
    {
        return view('settings.library', [
            'mode' => LeadingSource::mode(),
            'leading' => LeadingSource::leading(),
            'addPlayed' => LibrarySettings::addPlayedTracks(),
        ]);
    }

    public function librarySave(Request $request)
    {
        $validated = $request->validate([
            'leading_source' => ['required', 'in:'.implode(',', LeadingSource::MODES)],
        ]);

        Setting::set(LeadingSource::SETTING, $validated['leading_source']);
        Setting::set(LibrarySettings::ADD_PLAYED_TRACKS, $request->boolean('add_played_tracks') ? '1' : '0');

        return redirect()->route('settings.library')->with('success', 'Library settings saved.');
    }

    public function mqtt()
    {
        return view('settings.mqtt');
    }

    public function users()
    {
        $users = User::orderBy('name')->get();
        $appTokens = \App\Models\AdminToken::orderByDesc('last_used_at')->orderByDesc('id')->get();

        return view('settings.users', compact('users', 'appTokens'));
    }

    /** Signs an app out: its token stops working. */
    public function revokeAppToken(\App\Models\AdminToken $token)
    {
        $token->delete();

        return redirect()->route('settings.users')->with('status', 'app-signed-out');
    }

    public function health()
    {
        return view('settings.health');
    }

    public function devices()
    {
        return view('settings.devices');
    }

    public function spotifyConnect(SpotifyTokenService $spotify)
    {
        $spotifyDevices = [];

        if ($spotify->isConnected()) {
            try {
                $api = $spotify->makeApiClient();
                $spotifyDevices = $api->getMyDevices()['devices'] ?? [];

                // Sonos plays Spotify through its own cloud integration: it is the active
                // device in /me/player (restricted, no id) but never in the device list.
                $current = $api->getMyCurrentPlaybackInfo()['device'] ?? null;
                if ($current && !in_array($current['name'], array_column($spotifyDevices, 'name'), true)) {
                    $spotifyDevices[] = $current;
                }
            } catch (\Throwable) {
                // Spotify unreachable — show empty list
            }
        }

        // Local devices excluding the Spotify virtual device
        $localDevices = Device::where('device_driver', '!=', SpotifyDriver::class)
            ->orderBy('device_name')
            ->get();

        // Current mappings: spotify_connect_name → device_id
        $mappings = DeviceMeta::where('key', 'spotify_connect_name')
            ->pluck('device_id', 'value');

        return view('settings.spotify-connect', compact('spotifyDevices', 'localDevices', 'mappings'));
    }

    public function spotifyConnectSave(Request $request)
    {
        $data = $request->validate([
            'mappings' => ['nullable', 'array'],
            'mappings.*' => ['nullable', 'integer', 'exists:devices,id'],
        ]);

        // Remove all existing spotify_connect_name meta entries
        DeviceMeta::where('key', 'spotify_connect_name')->delete();

        foreach ($data['mappings'] ?? [] as $spotifyName => $deviceId) {
            if ($deviceId) {
                DeviceMeta::updateOrCreate(
                    ['device_id' => (int) $deviceId, 'key' => 'spotify_connect_name'],
                    ['value' => $spotifyName],
                );
            }
        }

        return back()->with('success', 'Spotify Connect mappings saved.');
    }

    public function spotifyLibrary(SpotifyTokenService $spotify)
    {
        return view('settings.spotify-library', [
            'connected' => $spotify->isConnected(),
            'hasRequiredScopes' => $spotify->hasRequiredScopes(),
            'tracksSyncedAt' => Setting::get('spotify_library_tracks_synced_at'),
            'playlistsSyncedAt' => Setting::get('spotify_library_playlists_synced_at'),
        ]);
    }

    public function spotifyLibrarySyncTracks(SpotifyLibraryImporter $importer)
    {
        $count = $importer->importSavedTracks();
        Setting::set('spotify_library_tracks_synced_at', now()->toIso8601String());

        return back()->with('success', "Imported {$count} saved tracks.");
    }

    public function spotifyLibrarySyncPlaylists(SpotifyLibraryImporter $importer)
    {
        $count = $importer->importPlaylists();
        Setting::set('spotify_library_playlists_synced_at', now()->toIso8601String());

        return back()->with('success', "Imported {$count} playlists.");
    }

    public function dlna()
    {
        $servers = DlnaServer::orderBy('friendly_name')->get();

        return view('settings.dlna', compact('servers'));
    }

    public function dlnaDiscover(DlnaServerDiscovery $discovery)
    {
        $discovered = $discovery->discover();

        return back()->with('success', 'Found '.count($discovered).' DLNA server(s).');
    }

    public function dlnaScan(DlnaServer $server, DlnaLibraryScanner $scanner)
    {
        $count = $scanner->scanServer($server);

        return back()->with('success', "Indexed {$count} tracks from {$server->friendly_name}.");
    }

    public function clients()
    {
        $clientCount = Client::count();
        $pendingCount = Client::where('status', 'pending')->count();
        $artworkExports = SdCardExport::all();

        return view('settings.clients', compact('clientCount', 'pendingCount', 'artworkExports'));
    }
}
