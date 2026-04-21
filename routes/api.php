<?php

use App\Http\Controllers\Api\DeviceController;
use Illuminate\Support\Facades\Route;

Route::get('/devices', [DeviceController::class, 'index']);
Route::get('/devices/{device}', [DeviceController::class, 'show']);

Route::post('/devices/{device}/{action}', [DeviceController::class, 'action'])
    ->whereIn('action', ['play', 'pause', 'stop', 'next', 'previous']);

Route::get('/devices/{device}/volume', [DeviceController::class, 'getVolume']);
Route::put('/devices/{device}/volume', [DeviceController::class, 'setVolume']);

Route::post('/devices/{device}/radio/{station}', [DeviceController::class, 'playRadio']);
