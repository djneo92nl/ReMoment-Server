<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToSetupWizard
{
    /**
     * Only the pages an admin actually lands on right after logging in.
     * Deliberately narrow: Settings/Profile pages must stay reachable even
     * with zero devices, since library setup doesn't require a device at all.
     */
    private const REDIRECT_FROM_ROUTES = ['devices.index', 'dashboard'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->method() !== 'GET'
            || $request->hasHeader('X-Livewire')
            || !$request->routeIs(...self::REDIRECT_FROM_ROUTES)
            || !auth()->check()
            || Setting::get('setup_wizard_dismissed') !== null
            || Device::count() > 0) {
            return $next($request);
        }

        return redirect()->route('setup.index');
    }
}
