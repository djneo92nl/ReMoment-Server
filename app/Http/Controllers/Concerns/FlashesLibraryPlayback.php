<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Library\NotPlayableException;
use App\Domain\Library\PlaybackResult;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;

/** The web play buttons: run a LibraryPlayback call, then go back with a success or error message. */
trait FlashesLibraryPlayback
{
    /** @param  string|null  $name  what is played (an album, a playlist...); null for an unnamed track */
    private function flashPlayback(\Closure $play, Device $device, ?string $name): RedirectResponse
    {
        $what = $name !== null ? "\"{$name}\"" : 'that track';

        try {
            $result = $play();
        } catch (NotPlayableException) {
            return back()->with('error', "{$device->device_name} can't play {$what}.");
        } catch (\Throwable $e) {
            return back()->with('error', "Could not play {$what} on {$device->device_name}: {$e->getMessage()}");
        }

        if ($result instanceof PlaybackResult && $result->playable < $result->total) {
            return back()->with('success', "Playing {$result->playable} of {$result->total} tracks from {$what} on {$device->device_name}.");
        }

        return back()->with('success', "Playing {$what} on {$device->device_name}.");
    }
}
