<?php

namespace App\Http\Controllers;

use App\Services\LastfmSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LastfmAuthController extends Controller
{
    public function __construct(private LastfmSessionService $lastfm) {}

    public function authorize(): RedirectResponse
    {
        return redirect($this->lastfm->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $token = $request->query('token');

        if (!is_string($token) || $token === '') {
            return redirect()->route('settings.index')
                ->with('error', 'Last.fm authorization failed: no token received.');
        }

        try {
            $this->lastfm->handleCallback($token);
        } catch (\Throwable $e) {
            return redirect()->route('settings.index')
                ->with('error', 'Last.fm authorization failed: '.$e->getMessage());
        }

        return redirect()->route('settings.index')
            ->with('success', 'Last.fm connected successfully.');
    }

    public function disconnect(): RedirectResponse
    {
        $this->lastfm->disconnect();

        return redirect()->route('settings.index')
            ->with('success', 'Last.fm disconnected.');
    }
}
