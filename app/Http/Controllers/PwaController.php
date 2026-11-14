<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Installable web app: manifest, a minimal service worker and the offline page.
 * Nothing here depends on the profile, the database or a session.
 */
class PwaController extends Controller
{
    /** stone-950 of DESIGN.md: the dark page colour, also the icons' near-black. */
    public const THEME = '#0c0a09';

    /** Bump when sw.js changes in a way old workers must drop their caches for. */
    public const SHELL_VERSION = 'v1';

    public function manifest(): Response
    {
        $manifest = [
            'name' => config('app.name', 'ReMoment'),
            'short_name' => 'ReMoment',
            'description' => 'Control the speakers and music of your home.',
            'start_url' => '/devices',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => self::THEME,
            'theme_color' => self::THEME,
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];

        return response(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function serviceWorker(): Response
    {
        $source = str_replace('__SHELL_VERSION__', self::SHELL_VERSION, (string) file_get_contents(resource_path('pwa/sw.js')));

        return response($source, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            // The browser must always re-check the worker; it is served from the root so its scope is the whole site.
            'Cache-Control' => 'no-cache, max-age=0',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    public function offline(): Response
    {
        return response()->view('pwa.offline', [], 200, ['Cache-Control' => 'no-store']);
    }
}
