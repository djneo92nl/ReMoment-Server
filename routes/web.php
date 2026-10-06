<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\ArtworkExportController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\LastfmAuthController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RadioStationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SpotifyAuthController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('devices.index');
});

Route::get('/devices/discover', fn () => view('devices.discover'))->name('devices.discover');
Route::resource('devices', DeviceController::class);
Route::post('/devices/{device}/standby', [DeviceController::class, 'standby'])->name('devices.standby');
Route::post('/devices/{device}/hidden', [DeviceController::class, 'toggleHidden'])->name('devices.toggle-hidden');
Route::post('/devices/{device}/sources/{deviceSource}/activate', [DeviceController::class, 'activateSource'])->name('devices.sources.activate');
Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');
Route::get('/multiroom', fn () => view('multiroom.index'))->name('multiroom.index');
Route::get('/receiver', fn () => view('receiver'))->name('receiver');
Route::get('/artists', [ArtistController::class, 'index'])->name('artists.index');
Route::resource('radio', RadioStationController::class);
Route::post('/radio/{radio}/favorite', [RadioStationController::class, 'favorite'])->name('radio.favorite');
Route::post('/radio/{radio}/play/{device}', [RadioStationController::class, 'play'])->name('radio.play');
Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('artists.show');
Route::post('/artists/{artist}/play/{device}', [ArtistController::class, 'play'])->name('artists.play');
Route::post('/artists/{artist}/favorite', [ArtistController::class, 'favorite'])->name('artists.favorite');
Route::get('/albums', [AlbumController::class, 'index'])->name('albums.index');
Route::get('/albums/{album}', [AlbumController::class, 'show'])->name('albums.show');
Route::post('/albums/{album}/play/{device}', [AlbumController::class, 'play'])->name('albums.play');
Route::post('/albums/{album}/fill', [AlbumController::class, 'fill'])->name('albums.fill');
Route::post('/albums/{album}/favorite', [AlbumController::class, 'favorite'])->name('albums.favorite');
Route::post('/tracks/{track}/play/{device}', [DeviceController::class, 'playTrack'])->name('tracks.play');
Route::get('/genres', [GenreController::class, 'index'])->name('genres.index');
Route::get('/genres/{slug}', [GenreController::class, 'show'])->name('genres.show');
Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
Route::get('/playlists', [PlaylistController::class, 'index'])->name('playlists.index');
Route::post('/playlists', [PlaylistController::class, 'store'])->name('playlists.store');
Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->name('playlists.show');
Route::delete('/playlists/{playlist}', [PlaylistController::class, 'destroy'])->name('playlists.destroy');
Route::post('/playlists/{playlist}/play/{device}', [PlaylistController::class, 'play'])->name('playlists.play');

Route::get('/dashboard', function () {
    return redirect()->route('devices.index');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');

    Route::get('/setup', fn () => view('setup.index'))->name('setup.index');

    Route::get('/devices/{device}/settings', fn (\App\Models\Device $device) => view('devices.settings', ['device' => $device]))->name('devices.settings');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/users', [SettingsController::class, 'users'])->name('settings.users');
    Route::get('/settings/listeners', [SettingsController::class, 'listeners'])->name('settings.listeners');
    Route::get('/settings/health', [SettingsController::class, 'health'])->name('settings.health');
    Route::post('/settings/listeners/start-all', [SettingsController::class, 'startAllListeners'])->name('settings.listeners.start-all');
    Route::post('/settings/listeners/{device}/start', [SettingsController::class, 'startListener'])->name('settings.listeners.start');

    Route::get('/settings/devices', [SettingsController::class, 'devices'])->name('settings.devices');

    Route::get('/settings/dlna', [SettingsController::class, 'dlna'])->name('settings.dlna');
    Route::post('/settings/dlna/discover', [SettingsController::class, 'dlnaDiscover'])->name('settings.dlna.discover');
    Route::post('/settings/dlna/{server}/scan', [SettingsController::class, 'dlnaScan'])->name('settings.dlna.scan');

    Route::get('/settings/library', [SettingsController::class, 'library'])->name('settings.library');
    Route::post('/settings/library', [SettingsController::class, 'librarySave'])->name('settings.library.save');

    Route::get('/settings/mqtt', [SettingsController::class, 'mqtt'])->name('settings.mqtt');

    Route::get('/settings/spotify', [SettingsController::class, 'spotify'])->name('settings.spotify');
    Route::get('/settings/lastfm', [SettingsController::class, 'lastfm'])->name('settings.lastfm');

    Route::get('/settings/spotify-connect', [SettingsController::class, 'spotifyConnect'])->name('settings.spotify-connect');
    Route::post('/settings/spotify-connect', [SettingsController::class, 'spotifyConnectSave'])->name('settings.spotify-connect.save');

    Route::get('/settings/spotify/library', [SettingsController::class, 'spotifyLibrary'])->name('settings.spotify-library');
    Route::post('/settings/spotify/library/sync-tracks', [SettingsController::class, 'spotifyLibrarySyncTracks'])->name('settings.spotify-library.sync-tracks');
    Route::post('/settings/spotify/library/sync-playlists', [SettingsController::class, 'spotifyLibrarySyncPlaylists'])->name('settings.spotify-library.sync-playlists');

    Route::get('/settings/spotify/authorize', [SpotifyAuthController::class, 'authorize'])->name('spotify.authorize');
    Route::get('/settings/spotify/callback', [SpotifyAuthController::class, 'callback'])->name('spotify.callback');
    Route::post('/settings/spotify/disconnect', [SpotifyAuthController::class, 'disconnect'])->name('spotify.disconnect');

    Route::get('/settings/lastfm/authorize', [LastfmAuthController::class, 'authorize'])->name('lastfm.authorize');
    Route::get('/settings/lastfm/callback', [LastfmAuthController::class, 'callback'])->name('lastfm.callback');
    Route::post('/settings/lastfm/disconnect', [LastfmAuthController::class, 'disconnect'])->name('lastfm.disconnect');

    Route::get('/settings/clients', [SettingsController::class, 'clients'])->name('settings.clients');
    Route::post('/settings/clients/artwork-export', [ArtworkExportController::class, 'store'])->name('settings.clients.artwork-export');
    Route::get('/settings/clients/artwork-export/download', [ArtworkExportController::class, 'download'])->name('settings.clients.artwork-export.download');
});

require __DIR__.'/auth.php';
