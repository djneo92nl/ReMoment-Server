<?php

namespace App\Services;

use App\Models\Setting;
use SpotifyWebAPI\Session;
use SpotifyWebAPI\SpotifyWebAPI;

class SpotifyTokenService
{
    private const SCOPES = [
        'user-read-currently-playing',
        'user-read-playback-state',
        'user-modify-playback-state',
    ];

    private Session $session;

    public function __construct()
    {
        $this->session = new Session(
            clientId: config('spotify.auth.client_id'),
            clientSecret: config('spotify.auth.client_secret'),
            redirectUri: route('spotify.callback'),
        );
    }

    public function getAuthorizationUrl(): string
    {
        return $this->session->getAuthorizeUrl([
            'scope' => self::SCOPES,
        ]);
    }

    public function handleCallback(string $code): void
    {
        $this->session->requestAccessToken($code);

        Setting::set('spotify_access_token', $this->session->getAccessToken());
        Setting::set('spotify_refresh_token', $this->session->getRefreshToken());
        Setting::set('spotify_token_expires_at', (string) $this->session->getTokenExpiration());
    }

    public function getAccessToken(): ?string
    {
        $accessToken = Setting::get('spotify_access_token');
        $refreshToken = Setting::get('spotify_refresh_token');
        $expiresAt = (int) Setting::get('spotify_token_expires_at', '0');

        if ($accessToken === null || $refreshToken === null) {
            return null;
        }

        // Refresh if expired or expiring within 60 seconds
        if (time() >= ($expiresAt - 60)) {
            $this->session->setRefreshToken($refreshToken);

            if (!$this->session->refreshAccessToken()) {
                return null;
            }

            $accessToken = $this->session->getAccessToken();
            Setting::set('spotify_access_token', $accessToken);
            Setting::set('spotify_token_expires_at', (string) $this->session->getTokenExpiration());
        }

        return $accessToken;
    }

    public function isConnected(): bool
    {
        return Setting::get('spotify_refresh_token') !== null;
    }

    public function disconnect(): void
    {
        Setting::forget('spotify_access_token');
        Setting::forget('spotify_refresh_token');
        Setting::forget('spotify_token_expires_at');
    }

    public function makeApiClient(): SpotifyWebAPI
    {
        $api = new SpotifyWebAPI;
        $api->setReturnType(SpotifyWebAPI::RETURN_ASSOC);
        $accessToken = $this->getAccessToken();

        if ($accessToken) {
            $api->setAccessToken($accessToken);
        }

        return $api;
    }
}
