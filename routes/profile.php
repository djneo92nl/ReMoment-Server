<?php

use App\Domain\Profile\Profile;
use Illuminate\Support\Facades\Route;

// Where a page of a feature the active profile lacks sends the visitor (App\Http\Middleware\EnforceProfile): a notice, not an error.
Route::get('/not-in-profile/{feature?}', function (?string $feature = null) {
    abort_unless($feature === null || config("profile.features.{$feature}") !== null, 404);

    return view('profile.unavailable', ['feature' => $feature]);
})->where('feature', '[a-z_]+')->name('profile.unavailable');

// Admin: what the server runs and the choice of profile (docs/architecture/profiles.md).
Route::middleware('auth')->get('/settings/profile', fn () => view('settings.profile'))->name('settings.profile');
