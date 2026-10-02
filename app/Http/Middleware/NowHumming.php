<?php

namespace App\Http\Middleware;

use App\Domain\Device\DeviceCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Easter egg: every API response hums along with whatever is playing.
 */
class NowHumming
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Now-Humming', Cache::remember('easter:humming', 5, function () {
            $track = DeviceCache::firstPlaying()?->track;

            if (!$track?->name) {
                return 'silence';
            }

            return Str::ascii(trim($track->name.' - '.($track->artist?->name ?? ''), ' -'));
        }));

        return $response;
    }
}
