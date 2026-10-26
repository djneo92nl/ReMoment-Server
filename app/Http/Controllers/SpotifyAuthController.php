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
        if (!SpotifyTokenService::hasCredentials()) {
            return redirect()->route('settings.spotify')
                ->with('error', 'Enter the Client ID and secret of your Spotify app first.');
        }

        return redirect($this->spotify->getAuthorizationUrl());
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],
            'client_secret' => [SpotifyTokenService::clientSecret() === '' ? 'required' : 'nullable', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],
        ]);

        SpotifyTokenService::saveCredentials($data['client_id'], $data['client_secret'] ?? null);

        return redirect()->route('settings.spotify')->with('success', 'Spotify app saved. Connect your account next.');
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
