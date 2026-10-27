<?php

namespace App\Domain\Credentials;

use App\Models\Setting;
use App\Services\LastfmSessionService;
use App\Services\SpotifyTokenService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The one registry of external-service credentials (workstream 14.4). Every client reads its
 * credentials and base URL through here, never from config()/constants directly, so a hosted relay can
 * later replace the user's own keys by changing `value()`/`baseUrl()` for a service whose source is `relay`.
 *
 * Secret fields are encrypted in the `settings` table (Crypt), masked in the UI, never returned by an API
 * and left out of backups. A value in `settings` wins over the `.env`/config fallback.
 */
final class ServiceCredentials
{
    public const SOURCE_OWN = 'own';

    /** Reserved for the hosted relay (after 1.0): nothing branches on it yet. */
    public const SOURCE_RELAY = 'relay';

    /** @var array<string, ServiceDeclaration>|null */
    private static ?array $declarations = null;

    /** @return array<string, ServiceDeclaration> */
    public static function all(): array
    {
        return self::$declarations ??= collect(self::declare())->keyBy('id')->all();
    }

    public static function find(string $id): ServiceDeclaration
    {
        return self::all()[$id] ?? throw new \InvalidArgumentException("Unknown service {$id}");
    }

    /** The current value: the settings row (decrypted for a secret), else the config/.env fallback, else ''. */
    public static function value(string $id, string $field): string
    {
        $f = self::find($id)->field($field);
        $stored = self::stored($f);

        if ($stored !== '') {
            return $stored;
        }

        return $f->config ? (string) config($f->config) : '';
    }

    public static function isSet(string $id, string $field): bool
    {
        return self::value($id, $field) !== '';
    }

    /** Every required field has a value. */
    public static function configured(string $id): bool
    {
        foreach (self::find($id)->fields as $f) {
            if ($f->required && !self::isSet($id, $f->name)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> labels of the required fields without a value */
    public static function missing(string $id): array
    {
        return collect(self::find($id)->fields)
            ->filter(fn (CredentialField $f) => $f->required && !self::isSet($id, $f->name))
            ->map(fn (CredentialField $f) => $f->label)->values()->all();
    }

    /** Where the value comes from: `settings`, `env` or null when unset. */
    public static function origin(string $id, string $field): ?string
    {
        $f = self::find($id)->field($field);

        return match (true) {
            self::stored($f) !== '' => 'settings',
            $f->config && (string) config($f->config) !== '' => 'env',
            default => null,
        };
    }

    /** What the UI may show about a secret: the last characters of a long value, never more. */
    public static function mask(string $id, string $field): ?string
    {
        $value = self::value($id, $field);

        if ($value === '') {
            return null;
        }

        return strlen($value) >= 12 ? '…'.substr($value, -2) : '';
    }

    /** The API base URL without a trailing slash: setting, then the declared config/.env path, then the default. */
    public static function baseUrl(string $id): string
    {
        $d = self::find($id);
        $url = (string) Setting::get($d->baseUrlSetting());

        if ($url === '' && $d->baseUrlConfig) {
            $url = (string) config($d->baseUrlConfig);
        }

        return rtrim($url !== '' ? $url : $d->baseUrl, '/');
    }

    /** `own` (the user's keys) or, once it exists, `relay`. */
    public static function source(string $id): string
    {
        return Setting::get(self::find($id)->sourceSetting()) === self::SOURCE_RELAY ? self::SOURCE_RELAY : self::SOURCE_OWN;
    }

    /**
     * Stores the given values (field name => string). An empty secret keeps the stored one; an empty
     * other field clears it. Unknown names are ignored.
     *
     * @param  array<string, string|null>  $values
     */
    public static function save(string $id, array $values): void
    {
        $d = self::find($id);
        $changed = [];

        foreach ($d->fields as $f) {
            if (!array_key_exists($f->name, $values)) {
                continue;
            }

            $new = trim((string) $values[$f->name]);

            if ($f->secret) {
                if ($new === '') {
                    continue;
                }
                $changed[$f->name] = $new !== self::stored($f);
                Setting::set($f->setting, Crypt::encryptString($new));

                continue;
            }

            $changed[$f->name] = $new !== self::stored($f);
            $new === '' ? Setting::forget($f->setting) : Setting::set($f->setting, $new);
        }

        if (array_key_exists('base_url', $values)) {
            $url = trim((string) $values['base_url']);
            $url === '' ? Setting::forget($d->baseUrlSetting()) : Setting::set($d->baseUrlSetting(), $url);
        }

        if ($d->onChanged && array_filter($changed)) {
            ($d->onChanged)($changed);
        }
    }

    /** Every settings key holding a secret: left out of backups and diagnostics bundles. @return list<string> */
    public static function secretSettingKeys(): array
    {
        $keys = [];
        foreach (self::all() as $d) {
            foreach ($d->fields as $f) {
                if ($f->secret) {
                    $keys[] = $f->setting;
                }
            }
        }

        return $keys;
    }

    /** Encrypts secret rows that were stored as plain text (by hand or by an older version). Returns how many. */
    public static function encryptPlaintext(): int
    {
        $n = 0;
        foreach (self::secretSettingKeys() as $key) {
            $row = Setting::find($key);
            if ($row && (string) $row->value !== '' && !self::looksEncrypted((string) $row->value)) {
                $row->update(['value' => Crypt::encryptString((string) $row->value)]);
                $n++;
            }
        }

        return $n;
    }

    /** Laravel's payload is base64 of JSON with iv, value and mac. */
    public static function looksEncrypted(string $value): bool
    {
        $payload = json_decode((string) base64_decode($value, true), true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    private static function stored(CredentialField $f): string
    {
        $raw = (string) Setting::get($f->setting);

        if ($raw === '' || !$f->secret) {
            return $raw;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (DecryptException) {
            // Unreadable (APP_KEY changed): behave as not set, so the .env fallback or a new entry takes over.
            return '';
        }
    }

    /** Test seam: forget the memoized declarations. */
    public static function flush(): void
    {
        self::$declarations = null;
    }

    /** @return list<ServiceDeclaration> */
    private static function declare(): array
    {
        $id16 = ['regex:/^[A-Za-z0-9]{16,64}$/'];

        return [
            new ServiceDeclaration(
                id: 'spotify',
                label: 'Spotify',
                icon: 'fa-brands fa-spotify',
                purpose: 'Playback tracking, the saved library and playlists, Spotify on your speakers.',
                fields: [
                    new CredentialField('client_id', 'Client ID', 'spotify_client_id', 'spotify.auth.client_id', required: true, rules: $id16),
                    new CredentialField('client_secret', 'Client secret', 'spotify_client_secret', 'spotify.auth.client_secret', type: 'password', secret: true, required: true, rules: $id16),
                ],
                baseUrl: 'https://api.spotify.com/v1',
                probes: ['spotify_app', 'spotify_account'],
                link: ['label' => 'developer.spotify.com/dashboard', 'url' => 'https://developer.spotify.com/dashboard'],
                steps: [
                    'Open the Spotify developer dashboard with a Premium account and create an app; select "Web API".',
                    'Add the redirect URI shown below to the app. It must match exactly, including http/https and the port.',
                    'Copy the app\'s Client ID and Client secret into the fields here and save.',
                    'Press Connect Spotify and approve. A Development Mode app allows 5 users (the owner counts as one), so every install uses its own app.',
                ],
                terms: 'Spotify Development Mode: 5 authorized users per Client ID and an app owner on Premium (see docs/architecture/spotify-app-setup.md).',
                baseUrlConfig: 'spotify.api_url',
                callbackRoute: 'spotify.callback',
                // The tokens belong to the app that issued them.
                onChanged: fn (array $changed) => app(SpotifyTokenService::class)->disconnect(),
                account: ['label' => 'Spotify account', 'route' => 'settings.spotify', 'connected' => fn () => app(SpotifyTokenService::class)->isConnected()],
            ),
            new ServiceDeclaration(
                id: 'lastfm',
                label: 'Last.fm',
                icon: 'fa-brands fa-lastfm',
                purpose: 'Scrobbling plays and artist, album and track metadata.',
                fields: [
                    new CredentialField('api_key', 'API key', 'lastfm_api_key', 'lastfm.api_key', type: 'password', secret: true, required: true, rules: ['regex:/^[A-Za-z0-9]{16,64}$/']),
                    new CredentialField('shared_secret', 'Shared secret', 'lastfm_shared_secret', 'lastfm.shared_secret', type: 'password', secret: true, required: true, rules: ['regex:/^[A-Za-z0-9]{16,64}$/'], hint: 'Needed for scrobbling and the account login.'),
                ],
                baseUrl: 'https://ws.audioscrobbler.com/2.0',
                probes: ['lastfm_key', 'lastfm_account'],
                link: ['label' => 'last.fm/api/account/create', 'url' => 'https://www.last.fm/api/account/create'],
                steps: [
                    'Log in at last.fm and open the API account page.',
                    'Create an API account: a name and a description are enough; the callback URL is the one shown below.',
                    'Copy the API key and the Shared secret into the fields here and save.',
                    'Press Connect Last.fm on the Last.fm page to scrobble as your account.',
                ],
                terms: 'Last.fm limits use of its data to non-commercial purposes and asks commercial or research use to contact partners@last.fm first (last.fm/api/tos, checked 2026-10-27).',
                callbackRoute: 'lastfm.callback',
                account: ['label' => 'Last.fm account', 'route' => 'settings.lastfm', 'connected' => fn () => app(LastfmSessionService::class)->isConnected()],
            ),
            new ServiceDeclaration(
                id: 'discogs',
                label: 'Discogs',
                icon: 'fa-solid fa-compact-disc',
                purpose: 'Release credits, formats and styles.',
                fields: [
                    new CredentialField('token', 'Personal access token', 'discogs_token', 'metadata.discogs_token', type: 'password', secret: true, required: true),
                ],
                baseUrl: 'https://api.discogs.com',
                probes: ['discogs'],
                link: ['label' => 'discogs.com/settings/developers', 'url' => 'https://www.discogs.com/settings/developers'],
                steps: [
                    'Log in at discogs.com (a free account is enough).',
                    'Open Settings › Developers.',
                    'Press "Generate new token" and copy it into the field here.',
                    'Without a token Discogs is skipped.',
                ],
                terms: 'Check the service\'s terms (Discogs API terms and rate limits) for what your use allows; its developer pages could not be read automatically on 2026-10-27.',
            ),
            new ServiceDeclaration(
                id: 'acoustid',
                label: 'AcoustID',
                icon: 'fa-solid fa-fingerprint',
                purpose: 'Looks audio fingerprints of your own files up to find the right MusicBrainz recording.',
                fields: [
                    new CredentialField('api_key', 'Application API key', 'acoustid_api_key', 'analysis.acoustid_key', type: 'password', secret: true, required: true),
                ],
                baseUrl: 'https://api.acoustid.org/v2',
                probes: ['acoustid'],
                link: ['label' => 'acoustid.org/new-application', 'url' => 'https://acoustid.org/new-application'],
                steps: [
                    'Sign in at acoustid.org (a free account).',
                    'Register an application at acoustid.org/new-application.',
                    'Copy the application\'s API key (the "client" key, not your personal user key) into the field here.',
                    'Audio analysis must be on (ANALYSIS_ENABLED) and fpcalc installed for fingerprints to be made.',
                ],
                terms: 'Free for non-commercial use, at most 3 requests per second; commercial use goes through acoustid.biz (acoustid.org/webservice, checked 2026-10-27).',
            ),
            new ServiceDeclaration(
                id: 'audiodb',
                label: 'TheAudioDB',
                icon: 'fa-solid fa-image',
                purpose: 'Artist photos, backdrops, logos, moods and descriptions.',
                fields: [
                    new CredentialField('api_key', 'API key', 'audiodb_api_key', 'metadata.audiodb_key', type: 'password', secret: true, hint: 'Optional: without one the public test key 123 is used.'),
                ],
                baseUrl: 'https://www.theaudiodb.com/api/v1/json',
                probes: ['audiodb'],
                link: ['label' => 'theaudiodb.com/pricing', 'url' => 'https://www.theaudiodb.com/pricing'],
                steps: [
                    'Nothing is needed to start: the public test key 123 works with about 30 requests per minute.',
                    'For more, upgrade at theaudiodb.com/pricing (Premium, 100 requests per minute, about $8 per month).',
                    'Your premium key is on your user profile after upgrading; paste it into the field here.',
                ],
                terms: 'Free 30, Premium 100, Business 120 requests per minute (theaudiodb.com/free_music_api, checked 2026-10-27); check the service\'s terms for attribution and commercial use.',
            ),
        ];
    }
}
