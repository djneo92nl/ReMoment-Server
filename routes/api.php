<?php

use App\Http\Controllers\Api\DeviceController;
use Illuminate\Support\Facades\Route;

Route::get('/devices', [DeviceController::class, 'index']);
Route::get('/devices/{device}', [DeviceController::class, 'show']);

Route::post('/devices/{device}/{action}', [DeviceController::class, 'action'])
    ->whereIn('action', ['play', 'pause', 'stop', 'next', 'previous']);

Route::get('/devices/{device}/volume', [DeviceController::class, 'getVolume']);
Route::put('/devices/{device}/volume', [DeviceController::class, 'setVolume']);

Route::get('/devices/{device}/sources', [DeviceController::class, 'sources']);
Route::post('/devices/{device}/sources/activate', [DeviceController::class, 'activateSource']);

Route::post('/devices/{device}/radio/{station}', [DeviceController::class, 'playRadio']);

Route::get('/devices/{device}/multiroom', [DeviceController::class, 'multiroom']);
Route::post('/devices/{device}/multiroom/join', [DeviceController::class, 'multiroomJoin']);
Route::delete('/devices/{device}/multiroom/leave', [DeviceController::class, 'multiroomLeave']);
