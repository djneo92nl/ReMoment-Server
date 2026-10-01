<?php

use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\InfoController;
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

Route::get('/devices/{device}/sources', [DeviceController::class, 'sources']);
Route::post('/devices/{device}/sources/activate', [DeviceController::class, 'activateSource']);

Route::post('/devices/{device}/radio/{station}', [DeviceController::class, 'playRadio']);

Route::get('/devices/{device}/multiroom', [DeviceController::class, 'multiroom']);
Route::post('/devices/{device}/multiroom/join', [DeviceController::class, 'multiroomJoin']);
Route::delete('/devices/{device}/multiroom/leave', [DeviceController::class, 'multiroomLeave']);

Route::post('/devices/{device}/library/play', [DeviceController::class, 'libraryPlay']);
Route::post('/devices/{device}/library/play-playlist', [DeviceController::class, 'libraryPlayPlaylist']);

Route::post('/clients/register', [ClientController::class, 'register']);
Route::get('/clients/status/{registrationToken}', [ClientController::class, 'status']);
Route::get('/clients/{apiToken}/devices', [ClientController::class, 'devices']);
Route::put('/clients/{apiToken}/heartbeat', [ClientController::class, 'heartbeat']);
