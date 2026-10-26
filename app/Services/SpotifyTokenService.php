<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use SpotifyWebAPI\Session;
use SpotifyWebAPI\SpotifyWebAPI;

class SpotifyTokenService
{
    private const SCOPES = [
        'user-read-currently-playing',
        'user-read-playback-state',
        'user-modify-playback-state',
        'playlist-read-private',
        'playlist-read-collaborative',
        'user-library-read',
    ];

    /**
     * Requested on connect for liking tracks (PUT /api/devices/{id}/like),
     * but not required: connections made before it was added keep working
     * and only liking fails until the admin reconnects Spotify.
     */
    private const OPTIONAL_SCOPES = [
        'user-library-modify',
    ];

    private Session $session;

    public function __construct()
    {
        $this->session = new Session(
            clientId: self::clientId(),
            clientSecret: self::clientSecret(),
            redirectUri: route('spotify.callback'),
        );
    }

    /** The admin's own Spotify app (Settings › Spotify), else SPOTIFY_CLIENT_ID from .env. */
    public static function clientId(): string
    {
        return (string) (Setting::get('spotify_client_id') ?: config('spotify.auth.client_id'));
    }

    public static function clientSecret(): string
    {
        $stored = Setting::get('spotify_client_secret');

        if ($stored) {
            try {
                return Crypt::decryptString($stored);
            } catch (DecryptException) {
                // Unreadable (APP_KEY changed): fall back to .env.
            }
        }

        return (string) config('spotify.auth.client_secret');
    }

    public static function hasCredentials(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /** Stores the credentials of the admin's Spotify app; a different Client ID invalidates the old connection. */
    public static function saveCredentials(string $clientId, ?string $clientSecret): void
    {
        $changed = $clientId !== self::clientId();

        Setting::set('spotify_client_id', $clientId);
        if ($clientSecret !== null && $clientSecret !== '') {
            Setting::set('spotify_client_secret', Crypt::encryptString($clientSecret));
            $changed = true;
        }

        if ($changed) {
            app(self::class)->disconnect();
        }
    }

    public function getAuthorizationUrl(): string
    {
        return $this->session->getAuthorizeUrl([
            'scope' => [...self::SCOPES, ...self::OPTIONAL_SCOPES],
        ]);
    }

    public function handleCallback(string $code): void
    {
        $this->session->requestAccessToken($code);

        Setting::set('spotify_access_token', $this->session->getAccessToken());
        Setting::set('spotify_refresh_token', $this->session->getRefreshToken());
        Setting::set('spotify_token_expires_at', (string) $this->session->getTokenExpiration());
        Setting::set('spotify_granted_scopes', implode(' ', $this->session->getScope()));
    }

    public function hasRequiredScopes(): bool
    {
        $granted = explode(' ', Setting::get('spotify_granted_scopes', ''));

        return empty(array_diff(self::SCOPES, $granted));
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
        Setting::forget('spotify_granted_scopes');
    }

    public function makeApiClient(): SpotifyWebAPI
    {
        $api = new SpotifyWebAPI(['return_assoc' => true]);
        $accessToken = $this->getAccessToken();

        if ($accessToken) {
            $api->setAccessToken($accessToken);
        }

        return $api;
    }
}
