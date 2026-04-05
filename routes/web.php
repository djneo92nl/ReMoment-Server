<?php

use App\Http\Controllers\DeviceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('devices.index');
});

Route::resource('devices', DeviceController::class);

Route::get('/dashboard', function () {
    return redirect()->route('devices.index');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/users', [SettingsController::class, 'users'])->name('settings.users');
    Route::delete('/settings/users/{user}', [SettingsController::class, 'destroyUser'])->name('settings.users.destroy');
    Route::get('/settings/listeners', [SettingsController::class, 'listeners'])->name('settings.listeners');
    Route::post('/settings/listeners/start-all', [SettingsController::class, 'startAllListeners'])->name('settings.listeners.start-all');
    Route::post('/settings/listeners/{device}/start', [SettingsController::class, 'startListener'])->name('settings.listeners.start');
});

require __DIR__.'/auth.php';
