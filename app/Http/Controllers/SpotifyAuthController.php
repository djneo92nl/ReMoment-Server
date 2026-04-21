<?php

namespace App\Http\Controllers;

use App\Services\SpotifyTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpotifyAuthController extends Controller
{
    public function __construct(private SpotifyTokenService $spotify) {}

    public function authorize(): RedirectResponse
    {
        return redirect($this->spotify->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');

        if (!is_string($code) || $code === '') {
            return redirect()->route('settings.index')
                ->with('error', 'Spotify authorization failed: no code received.');
        }

        try {
            $this->spotify->handleCallback($code);
        } catch (\Throwable $e) {
            return redirect()->route('settings.index')
                ->with('error', 'Spotify authorization failed: '.$e->getMessage());
        }

        return redirect()->route('settings.index')
            ->with('success', 'Spotify connected successfully.');
    }

    public function disconnect(): RedirectResponse
    {
        $this->spotify->disconnect();

        return redirect()->route('settings.index')
            ->with('success', 'Spotify disconnected.');
    }
}
