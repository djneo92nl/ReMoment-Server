<?php

use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceSettingsController;
use App\Http\Controllers\Api\InfoController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\SourceControlsController;
use Illuminate\Support\Facades\Route;

Route::get('/info', InfoController::class);

Route::get('/devices', [DeviceController::class, 'index']);
Route::get('/devices/{device}', [DeviceController::class, 'show']);

Route::post('/devices/{device}/{action}', [DeviceController::class, 'action'])
    ->whereIn('action', ['play', 'pause', 'stop', 'next', 'previous']);

Route::get('/devices/{device}/volume', [DeviceController::class, 'getVolume']);
Route::put('/devices/{device}/volume', [DeviceController::class, 'setVolume']);
Route::get('/devices/{device}/mute', [DeviceController::class, 'getMute']);
Route::put('/devices/{device}/mute', [DeviceController::class, 'setMute']);

Route::put('/devices/{device}/seek', [DeviceController::class, 'seek']);
Route::get('/devices/{device}/queue', [DeviceController::class, 'queue']);
Route::post('/devices/{device}/queue/jump', [DeviceController::class, 'queueJump']);

Route::put('/devices/{device}/shuffle', [DeviceController::class, 'setShuffle']);
Route::put('/devices/{device}/repeat', [DeviceController::class, 'setRepeat']);
Route::put('/devices/{device}/like', [DeviceController::class, 'setLike']);
Route::put('/devices/{device}/power', [DeviceController::class, 'setPower']);

Route::get('/devices/{device}/sound-adjustment', [DeviceSettingsController::class, 'getSoundAdjustment']);
Route::put('/devices/{device}/sound-adjustment', [DeviceSettingsController::class, 'setSoundAdjustment']);
Route::get('/devices/{device}/bluetooth', [DeviceSettingsController::class, 'getBluetooth']);
Route::put('/devices/{device}/bluetooth', [DeviceSettingsController::class, 'setBluetooth']);
Route::delete('/devices/{device}/bluetooth/devices/{deviceId}', [DeviceSettingsController::class, 'removeBluetoothDevice'])->where('deviceId', '.+');
Route::get('/devices/{device}/network', [DeviceSettingsController::class, 'getNetwork']);
Route::put('/devices/{device}/network/interface', [DeviceSettingsController::class, 'setNetworkInterface']);
Route::put('/devices/{device}/network/wired', [DeviceSettingsController::class, 'setWiredNetwork']);
Route::put('/devices/{device}/network/wifi', [DeviceSettingsController::class, 'joinWifi']);
Route::get('/devices/{device}/wireless-speakers', [DeviceSettingsController::class, 'getWirelessSpeakers']);
Route::post('/devices/{device}/wireless-speakers/scan', [DeviceSettingsController::class, 'scanWirelessSpeakers']);
Route::get('/devices/{device}/info', [DeviceSettingsController::class, 'getInfo']);
Route::put('/devices/{device}/info', [DeviceSettingsController::class, 'setInfo']);

Route::get('/devices/{device}/sources', [DeviceController::class, 'sources']);
Route::post('/devices/{device}/sources/activate', [DeviceController::class, 'activateSource']);

Route::get('/devices/{device}/controls', [SourceControlsController::class, 'index']);
Route::post('/devices/{device}/controls/{control}', [SourceControlsController::class, 'run']);

Route::get('/devices/{device}/radio', [DeviceController::class, 'radioStations']);
Route::put('/radio/{station}/favorite', [DeviceController::class, 'favoriteRadio']);
Route::post('/devices/{device}/radio/{station}', [DeviceController::class, 'playRadio']);

Route::get('/devices/{device}/multiroom', [DeviceController::class, 'multiroom']);
Route::post('/devices/{device}/multiroom/join', [DeviceController::class, 'multiroomJoin']);
Route::delete('/devices/{device}/multiroom/leave', [DeviceController::class, 'multiroomLeave']);

Route::post('/devices/{device}/library/play', [DeviceController::class, 'libraryPlay']);
Route::post('/devices/{device}/library/play-playlist', [DeviceController::class, 'libraryPlayPlaylist']);
Route::post('/devices/{device}/library/play-album', [DeviceController::class, 'libraryPlayAlbum']);
Route::post('/devices/{device}/library/play-artist', [DeviceController::class, 'libraryPlayArtist']);

Route::get('/library/artists', [LibraryController::class, 'artists']);
Route::get('/library/artists/{artist}', [LibraryController::class, 'artist']);
Route::put('/library/artists/{artist}/favorite', [LibraryController::class, 'favoriteArtist']);
Route::get('/library/albums', [LibraryController::class, 'albums']);
Route::get('/library/albums/{album}', [LibraryController::class, 'album']);
Route::put('/library/albums/{album}/favorite', [LibraryController::class, 'favoriteAlbum']);
Route::get('/library/genres', [LibraryController::class, 'genres']);
Route::get('/library/genres/{genre:slug}', [LibraryController::class, 'genre']);
Route::get('/library/playlists', [LibraryController::class, 'playlists']);
Route::get('/library/playlists/{playlist}', [LibraryController::class, 'playlist']);
Route::get('/library/recent', [LibraryController::class, 'recent']);
Route::get('/library/favorites', [LibraryController::class, 'favorites']);
Route::get('/library/search', [LibraryController::class, 'search']);

Route::post('/clients/register', [ClientController::class, 'register']);
Route::get('/clients/status/{registrationToken}', [ClientController::class, 'status']);
Route::get('/clients/{apiToken}/devices', [ClientController::class, 'devices']);
Route::put('/clients/{apiToken}/heartbeat', [ClientController::class, 'heartbeat']);
Route::get('/clients/{apiToken}/artwork', [ClientController::class, 'artwork']);
