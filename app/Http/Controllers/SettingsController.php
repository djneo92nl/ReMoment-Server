<?php

namespace App\Http\Controllers;

use App\Domain\Device\DeviceCache;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Integrations\Spotify\SpotifyDevice;
use App\Models\Client;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\DlnaServer;
use App\Models\Media\Track;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dlna\DlnaLibraryScanner;
use App\Services\Dlna\DlnaServerDiscovery;
use App\Services\SpotifyTokenService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $userCount = User::count();
        $deviceCount = Device::count();
        $spotifyConnected = app(SpotifyTokenService::class)->isConnected();
        $dlnaServerCount = DlnaServer::count();
        $dlnaTrackCount = Track::where('source', 'dlna')->count();

        return view('settings.index', compact('userCount', 'deviceCount', 'spotifyConnected', 'dlnaServerCount', 'dlnaTrackCount'));
    }

    public function users()
    {
        $users = User::orderBy('name')->get();

        return view('settings.users', compact('users'));
    }

    public function listeners()
    {
        if (app(SpotifyTokenService::class)->isConnected()) {
            SpotifyDevice::findOrProvision();
        }

        $devices = Device::orderBy('device_name')->get()->map(function (Device $device) {
            return [
                'device' => $device,
                'listener_running' => DeviceCache::isListenerRunning($device->id),
                'can_start' => in_array($device->device_driver_name, ['ASE', 'Spotify']),
            ];
        });

        return view('settings.listeners', compact('devices'));
    }

    public function devices()
    {
        return view('settings.devices');
    }

    public function startListener(Device $device)
    {
        if (!in_array($device->device_driver_name, ['ASE', 'Spotify'])) {
            return back()->with('error', 'Listeners can only be started for ASE or Spotify devices.');
        }

        if (DeviceCache::isListenerRunning($device->id)) {
            return back()->with('error', "Listener for {$device->device_name} is already running.");
        }

        if ($device->device_driver_name === 'Spotify') {
            $cmd = 'php '.base_path('artisan').' device-spotify:listen > /dev/null 2>&1 &';
        } else {
            $cmd = 'php '.base_path('artisan')." device-ase:listen-single '{$device->id}' > /dev/null 2>&1 &";
        }

        shell_exec($cmd);

        return back()->with('success', "Listener started for {$device->device_name}.");
    }

    public function spotifyConnect(SpotifyTokenService $spotify)
    {
        $spotifyDevices = [];

        if ($spotify->isConnected()) {
            try {
                $spotifyDevices = $spotify->makeApiClient()->getMyDevices()['devices'] ?? [];
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

        return view('settings.clients', compact('clientCount', 'pendingCount'));
    }

    public function startAllListeners()
    {
        $devices = Device::whereIn('device_driver_name', ['ASE', 'Spotify'])->get();
        $started = 0;

        foreach ($devices as $device) {
            if (!DeviceCache::isListenerRunning($device->id)) {
                if ($device->device_driver_name === 'Spotify') {
                    $cmd = 'php '.base_path('artisan').' device-spotify:listen > /dev/null 2>&1 &';
                } else {
                    $cmd = 'php '.base_path('artisan')." device-ase:listen-single '{$device->id}' > /dev/null 2>&1 &";
                }
                shell_exec($cmd);
                $started++;
            }
        }

        $message = $started > 0
            ? "Started {$started} ".($started === 1 ? 'listener' : 'listeners').'.'
            : 'All listeners are already running.';

        return back()->with('success', $message);
    }
}
