<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\HistoryController;
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
Route::post('/devices/{device}/sources/{deviceSource}/activate', [DeviceController::class, 'activateSource'])->name('devices.sources.activate');
Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');
Route::get('/artists', [ArtistController::class, 'index'])->name('artists.index');
Route::resource('radio', RadioStationController::class);
Route::post('/radio/{radio}/play/{device}', [RadioStationController::class, 'play'])->name('radio.play');
Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('artists.show');
Route::get('/albums/{album}', [AlbumController::class, 'show'])->name('albums.show');

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

    Route::get('/settings/spotify/authorize', [SpotifyAuthController::class, 'authorize'])->name('spotify.authorize');
    Route::get('/settings/spotify/callback', [SpotifyAuthController::class, 'callback'])->name('spotify.callback');
    Route::post('/settings/spotify/disconnect', [SpotifyAuthController::class, 'disconnect'])->name('spotify.disconnect');
});

require __DIR__.'/auth.php';
