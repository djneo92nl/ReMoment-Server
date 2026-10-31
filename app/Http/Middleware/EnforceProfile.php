<?php

namespace App\Http\Middleware;

use App\Domain\Profile\Profile;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the URLs of a feature the active profile lacks closed (config profile.routes): the API answers
 * 422 not_available_in_profile naming the profile, a page redirects to /not-in-profile. Open in every profile
 * when no row matches, so Control still has devices, playback, history, radio, alerts and health.
 */
class EnforceProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $feature = Profile::featureForRequest($request);

        if ($feature === null || Profile::allows($feature)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson() || $request->header('X-Livewire')) {
            return response()->json([
                'error' => 'not_available_in_profile',
                'message' => Profile::unavailableMessage($feature),
                'profile' => Profile::current(),
                'feature' => $feature,
                'required_profile' => Profile::lowestFor($feature),
            ], 422);
        }

        return redirect()->route('profile.unavailable', ['feature' => $feature]);
    }
}
