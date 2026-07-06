<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\HistoryController;
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
Route::post('/radio/{radio}/play/{device}', [RadioStationController::class, 'play'])->name('radio.play');
Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('artists.show');
Route::get('/albums/{album}', [AlbumController::class, 'show'])->name('albums.show');
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

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/users', [SettingsController::class, 'users'])->name('settings.users');
    Route::get('/settings/listeners', [SettingsController::class, 'listeners'])->name('settings.listeners');
    Route::post('/settings/listeners/start-all', [SettingsController::class, 'startAllListeners'])->name('settings.listeners.start-all');
    Route::post('/settings/listeners/{device}/start', [SettingsController::class, 'startListener'])->name('settings.listeners.start');

    Route::get('/settings/devices', [SettingsController::class, 'devices'])->name('settings.devices');

    Route::get('/settings/dlna', [SettingsController::class, 'dlna'])->name('settings.dlna');
    Route::post('/settings/dlna/discover', [SettingsController::class, 'dlnaDiscover'])->name('settings.dlna.discover');
    Route::post('/settings/dlna/{server}/scan', [SettingsController::class, 'dlnaScan'])->name('settings.dlna.scan');

    Route::get('/settings/spotify-connect', [SettingsController::class, 'spotifyConnect'])->name('settings.spotify-connect');
    Route::post('/settings/spotify-connect', [SettingsController::class, 'spotifyConnectSave'])->name('settings.spotify-connect.save');

    Route::get('/settings/spotify/library', [SettingsController::class, 'spotifyLibrary'])->name('settings.spotify-library');
    Route::post('/settings/spotify/library/sync-tracks', [SettingsController::class, 'spotifyLibrarySyncTracks'])->name('settings.spotify-library.sync-tracks');
    Route::post('/settings/spotify/library/sync-playlists', [SettingsController::class, 'spotifyLibrarySyncPlaylists'])->name('settings.spotify-library.sync-playlists');

    Route::get('/settings/spotify/authorize', [SpotifyAuthController::class, 'authorize'])->name('spotify.authorize');
    Route::get('/settings/spotify/callback', [SpotifyAuthController::class, 'callback'])->name('spotify.callback');
    Route::post('/settings/spotify/disconnect', [SpotifyAuthController::class, 'disconnect'])->name('spotify.disconnect');

    Route::get('/settings/clients', [SettingsController::class, 'clients'])->name('settings.clients');
});

require __DIR__.'/auth.php';
