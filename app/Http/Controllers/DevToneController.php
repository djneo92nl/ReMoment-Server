<?php

namespace App\Http\Controllers;

use App\Domain\Developer\Tones;
use App\Domain\Storage\Proxies;

/** A generated test signal for a speaker (`/dev/tone/sine-1k.wav`), unauthenticated like /stream. See Tones. */
class DevToneController extends Controller
{
    public function show(string $name)
    {
        abort_unless(Tones::known($name), 404);

        try {
            $path = Tones::ensure($name);
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        return response()->file(Proxies::disk()->path($path), ['Content-Type' => 'audio/wav', 'Accept-Ranges' => 'bytes']);
    }
}
