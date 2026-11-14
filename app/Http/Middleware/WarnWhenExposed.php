<?php

namespace App\Http\Middleware;

use App\Domain\Network\ExposureAlert;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Warn-only: notes a request that looks public (cache only) so alerts:check and /settings/health can warn. Never blocks. */
class WarnWhenExposed
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            ExposureAlert::observe($request);
        } catch (\Throwable $e) {
            report($e);
        }

        return $next($request);
    }
}
