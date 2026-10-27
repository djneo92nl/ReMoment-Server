<?php

namespace App\Domain\Health;

use App\Domain\Analysis\Tools;
use App\Domain\Credentials\ServiceCredentials;
use App\Models\StorageLocation;
use App\Services\LastfmSessionService;
use App\Services\MetadataHttp;
use App\Services\SpotifyTokenService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Probes every external service the server depends on: is it reachable, and does the credential
 * we hold still work? One cheap read-only request per service, results cached so the health page
 * doesn't hit rate-limited APIs on every visit (`refresh()` forces a new round).
 */
class ExternalServices
{
    public const CACHE_KEY = 'health:external-services';

    private const TTL = 900;

    private const TIMEOUT = 8;

    /** Radiohead: a stable MusicBrainz id every metadata service knows. */
    private const PROBE_MBID = 'a74b1b7f-71a5-4011-9441-d0b5e4122711';

    /** @return array<string, array{name: string, group: string, status: string, message: string}> */
    public function all(): array
    {
        return Cache::get(self::CACHE_KEY)['results'] ?? [];
    }

    public function checkedAt(): ?\Illuminate\Support\Carbon
    {
        $at = Cache::get(self::CACHE_KEY)['at'] ?? null;

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /** @return list<string> the ids of every probe (credential declarations point at these) */
    public function probeIds(): array
    {
        return array_keys($this->probes());
    }

    /**
     * Runs the probes and stores the outcome: every probe, or only the given ids (the rest of the
     * cached results and their time stay).
     *
     * @param  list<string>|null  $only
     */
    public function refresh(?array $only = null): array
    {
        $results = $only === null ? [] : $this->all();

        foreach ($this->probes() as $key => [$name, $group, $probe]) {
            if ($only !== null && !in_array($key, $only, true)) {
                continue;
            }

            try {
                $check = $probe();
            } catch (ConnectionException $e) {
                $check = ServiceCheck::error('Unreachable: '.$this->short($e->getMessage()));
            } catch (\Throwable $e) {
                $check = ServiceCheck::error($this->short($e->getMessage()));
            }

            $result = $check->toArray();
            $result['message'] = $this->redact($result['message']);
            $results[$key] = ['name' => $name, 'group' => $group] + $result;
        }

        $at = $only !== null ? ($this->checkedAt() ?? now()) : now();
        Cache::put(self::CACHE_KEY, ['at' => $at->toIso8601String(), 'results' => $results], self::TTL * 4);

        return $results;
    }

    /** @return array<string, array{0: string, 1: string, 2: \Closure(): ServiceCheck}> */
    private function probes(): array
    {
        $probes = [
            'spotify_app' => ['Spotify app credentials', 'Streaming', fn () => $this->spotifyApp()],
            'spotify_account' => ['Spotify account', 'Streaming', fn () => $this->spotifyAccount()],
            'lastfm_key' => ['Last.fm API key', 'Scrobbling', fn () => $this->lastfmKey()],
            'lastfm_account' => ['Last.fm account', 'Scrobbling', fn () => $this->lastfmAccount()],
            'musicbrainz' => ['MusicBrainz', 'Metadata', fn () => $this->reachable('https://musicbrainz.org/ws/2/artist/'.self::PROBE_MBID, ['fmt' => 'json'])],
            'audiodb' => ['TheAudioDB', 'Metadata', fn () => $this->audioDb()],
            'discogs' => ['Discogs', 'Metadata', fn () => $this->discogs()],
            'jellyfin' => ['Jellyfin', 'Media servers', fn () => $this->jellyfin()],
            'wikipedia' => ['Wikipedia', 'Metadata', fn () => $this->reachable('https://en.wikipedia.org/api/rest_v1/page/summary/Radiohead')],
            'coverart' => ['Cover Art Archive', 'Metadata', fn () => $this->reachable('https://coverartarchive.org/release-group/'.self::PROBE_MBID)],
            'radiobrowser' => ['radio-browser.info', 'Metadata', fn () => $this->reachable('https://de1.api.radio-browser.info/json/stats')],
            'lrclib' => ['LRCLIB (lyrics)', 'Metadata', fn () => $this->reachable('https://lrclib.net/api/get', ['artist_name' => 'Radiohead', 'track_name' => 'Creep'])],
        ];

        if (\App\Domain\Radio\RadioSettings::ntsEnabled()) {
            $probes['nts'] = ['NTS Radio', 'Metadata', fn () => $this->reachable('https://www.nts.live/api/v2/live')];
        }

        // Audio analysis of the own files (docs/architecture/audio-analysis.md).
        $probes['ffmpeg'] = ['ffmpeg', 'Analysis', fn () => $this->analysisBinary(Tools::ffmpeg(), 'Loudness and BPM/key analysis')];
        $probes['fpcalc'] = ['fpcalc (Chromaprint)', 'Analysis', fn () => $this->analysisBinary(Tools::fpcalc(), 'Audio fingerprints')];
        $probes['acoustid'] = ['AcoustID', 'Analysis', fn () => $this->acoustId()];
        $probes['essentia'] = ['Essentia sidecar', 'Analysis', fn () => $this->essentia()];

        foreach (StorageLocation::all() as $location) {
            $probes["storage_{$location->id}"] = ["Storage: {$location->name}", 'Storage', fn () => $this->storage($location)];
        }

        return $probes;
    }

    private function analysisBinary(string $binary, string $for): ServiceCheck
    {
        if (!config('analysis.enabled')) {
            return ServiceCheck::disabled('Audio analysis is off (ANALYSIS_ENABLED)');
        }

        $version = Tools::version($binary);

        return $version !== null ? ServiceCheck::ok($version) : ServiceCheck::error("{$binary} is not installed ({$for} is skipped)");
    }

    private function acoustId(): ServiceCheck
    {
        if (!config('analysis.enabled')) {
            return ServiceCheck::disabled('Audio analysis is off (ANALYSIS_ENABLED)');
        }
        if (!ServiceCredentials::isSet('acoustid', 'api_key')) {
            return ServiceCheck::disabled('No AcoustID key set (Settings › Services): fingerprints are stored but not looked up');
        }

        // A deliberately invalid fingerprint: a valid key is answered with a fingerprint error, a bad key with its own.
        $response = $this->http()->asForm()->post(ServiceCredentials::baseUrl('acoustid').'/lookup', [
            'client' => ServiceCredentials::value('acoustid', 'api_key'), 'format' => 'json', 'duration' => 1, 'fingerprint' => 'AQAA',
        ]);

        return $this->byStatus($response) ?? match ($response->json('error.code')) {
            4 => ServiceCheck::error('API key rejected'),
            default => ServiceCheck::ok('API key accepted'),
        };
    }

    private function essentia(): ServiceCheck
    {
        if (!config('analysis.enabled')) {
            return ServiceCheck::disabled('Audio analysis is off (ANALYSIS_ENABLED)');
        }
        if (!config('analysis.essentia_url')) {
            return ServiceCheck::disabled('ANALYSIS_ESSENTIA_URL not set: no BPM, key or mood analysis');
        }

        $response = $this->http()->get(rtrim(config('analysis.essentia_url'), '/').'/health');

        if (!$response->ok()) {
            return ServiceCheck::error("Sidecar answered HTTP {$response->status()}");
        }

        $ml = (bool) $response->json('ml');

        return $ml === (bool) config('analysis.essentia_ml')
            ? ServiceCheck::ok('Essentia '.($response->json('essentia') ?? '').($ml ? ' with ML models' : ' (no ML models)'))
            : ServiceCheck::warning('Sidecar has ML models '.($ml ? 'on' : 'off').' but ANALYSIS_ESSENTIA_ML says '.(config('analysis.essentia_ml') ? 'on' : 'off'));
    }

    private function http(): PendingRequest
    {
        return MetadataHttp::request()->timeout(self::TIMEOUT);
    }

    /** Any answer below 500 means the host is up; 429 is reported as a degraded rate limit. */
    private function reachable(string $url, array $query = []): ServiceCheck
    {
        $response = $this->http()->get($url, $query);

        return $this->byStatus($response) ?? ServiceCheck::ok('Reachable');
    }

    private function byStatus(Response $response): ?ServiceCheck
    {
        return match (true) {
            $response->status() === 429 => ServiceCheck::warning('Rate limited (HTTP 429), try again later'),
            $response->serverError() => ServiceCheck::error("Service error (HTTP {$response->status()})"),
            default => null,
        };
    }

    private function spotifyApp(): ServiceCheck
    {
        $id = SpotifyTokenService::clientId();
        $secret = SpotifyTokenService::clientSecret();

        if (!$id || !$secret) {
            return ServiceCheck::disabled('No Spotify app configured: enter its Client ID and secret in Settings › Services');
        }

        $response = Http::timeout(self::TIMEOUT)->asForm()->withBasicAuth($id, $secret)
            ->post('https://accounts.spotify.com/api/token', ['grant_type' => 'client_credentials']);

        if ($check = $this->byStatus($response)) {
            return $check;
        }

        return $response->ok()
            ? ServiceCheck::ok('Client ID and secret accepted')
            : ServiceCheck::error($response->json('error_description') ?? "Rejected (HTTP {$response->status()})");
    }

    private function spotifyAccount(): ServiceCheck
    {
        $spotify = app(SpotifyTokenService::class);

        if (!SpotifyTokenService::hasCredentials()) {
            return ServiceCheck::disabled('Spotify is not configured');
        }
        if (!$spotify->isConnected()) {
            return ServiceCheck::disabled('Not connected: connect in Settings');
        }
        if (!$spotify->getAccessToken()) {
            return ServiceCheck::error('Token refresh failed: reconnect Spotify in Settings');
        }

        $me = $spotify->makeApiClient()->me();
        $name = $me['display_name'] ?? $me['id'] ?? 'account';

        if (!$spotify->hasRequiredScopes()) {
            return ServiceCheck::warning("Connected as {$name}, but permissions are missing: reconnect Spotify");
        }

        return ServiceCheck::ok("Connected as {$name}");
    }

    private function lastfmKey(): ServiceCheck
    {
        if (!ServiceCredentials::isSet('lastfm', 'api_key')) {
            return ServiceCheck::disabled('No Last.fm API key set (Settings › Services)');
        }

        $response = $this->http()->get(ServiceCredentials::baseUrl('lastfm').'/', [
            'method' => 'artist.getinfo', 'artist' => 'Radiohead',
            'api_key' => ServiceCredentials::value('lastfm', 'api_key'), 'format' => 'json',
        ]);

        return $this->byStatus($response) ?? $this->lastfmError($response->json('error'), $response->json('message'))
            ?? ServiceCheck::ok('API key accepted');
    }

    private function lastfmAccount(): ServiceCheck
    {
        $session = app(LastfmSessionService::class);

        if (!ServiceCredentials::configured('lastfm')) {
            return ServiceCheck::disabled('Last.fm API key and shared secret not set (Settings › Services)');
        }
        if (!$session->isConnected()) {
            return ServiceCheck::disabled('Not connected: connect in Settings');
        }

        try {
            $session->signedRequest(['method' => 'user.getinfo']);
        } catch (\RuntimeException $e) {
            return str_contains($e->getMessage(), 'error 9')
                ? ServiceCheck::error('Session expired: reconnect Last.fm in Settings')
                : ServiceCheck::error($this->short($e->getMessage()));
        }

        return ServiceCheck::ok('Scrobbling as '.$session->username());
    }

    private function lastfmError(mixed $code, mixed $message): ?ServiceCheck
    {
        return match (true) {
            $code === null => null,
            in_array($code, [10, 26], true) => ServiceCheck::error('API key invalid or suspended'),
            $code === 29 => ServiceCheck::warning('Rate limited, try again later'),
            default => ServiceCheck::error("Last.fm error {$code}: {$message}"),
        };
    }

    private function audioDb(): ServiceCheck
    {
        $key = ServiceCredentials::value('audiodb', 'api_key');
        $response = $this->http()->get(ServiceCredentials::baseUrl('audiodb')."/{$key}/artist-mb.php", ['i' => self::PROBE_MBID]);

        if ($check = $this->byStatus($response)) {
            return $check;
        }

        if (!$response->ok() || empty($response->json('artists'))) {
            return ServiceCheck::error("Key rejected or no data (HTTP {$response->status()}): check the key in Settings › Services");
        }

        return $key === '123' ? ServiceCheck::ok('Working with the public test key (about 30 requests a minute)') : ServiceCheck::ok('Key accepted');
    }

    private function discogs(): ServiceCheck
    {
        $token = ServiceCredentials::value('discogs', 'token');

        if (!$token) {
            return ServiceCheck::disabled('No Discogs token set (Settings › Services)');
        }

        $response = $this->http()->withHeaders(['Authorization' => "Discogs token={$token}"])
            ->get(ServiceCredentials::baseUrl('discogs').'/oauth/identity');

        if ($check = $this->byStatus($response)) {
            return $check;
        }

        return $response->ok()
            ? ServiceCheck::ok('Token accepted for '.$response->json('username'))
            : ServiceCheck::error('Token rejected: check it in Settings › Services');
    }

    private function jellyfin(): ServiceCheck
    {
        if (!ServiceCredentials::isSet('jellyfin', 'api_key')) {
            return ServiceCheck::disabled('No Jellyfin key set (Settings › Services): the TV card shows the app name only');
        }

        $response = $this->http()->withHeaders(['Authorization' => 'MediaBrowser Token="'.ServiceCredentials::value('jellyfin', 'api_key').'"'])
            ->get(ServiceCredentials::baseUrl('jellyfin').'/System/Info');

        if ($check = $this->byStatus($response)) {
            return $check;
        }

        return $response->ok()
            ? ServiceCheck::ok('Connected to '.$response->json('ServerName'))
            : ServiceCheck::error('Key rejected or server not found: check it and the base URL in Settings › Services');
    }

    /** One storage location's probe with its failure turned into an error check (used by the alert checks). */
    public function probeStorage(StorageLocation $location): ServiceCheck
    {
        try {
            return $this->storage($location);
        } catch (\Throwable $e) {
            return ServiceCheck::error($this->short($e->getMessage()));
        }
    }

    private function storage(StorageLocation $location): ServiceCheck
    {
        if ($location->isLocal()) {
            $root = $location->config['root'] ?? '';

            return is_dir($root) && is_readable($root)
                ? ServiceCheck::ok($root)
                : ServiceCheck::error("{$root} is missing or not readable");
        }

        $location->disk()->directories();

        return ServiceCheck::ok('Bucket reachable, credentials accepted');
    }

    /** An error message may carry the request URL; a credential must never end up in cached health output. */
    private function redact(string $message): string
    {
        foreach (ServiceCredentials::all() as $id => $service) {
            foreach ($service->fields as $field) {
                $value = ServiceCredentials::value($id, $field->name);
                if (strlen($value) >= 6) {
                    $message = str_replace([$value, rawurlencode($value)], '***', $message);
                }
            }
        }

        return $message;
    }

    private function short(string $message): string
    {
        return \Illuminate\Support\Str::limit(strtok($message, "\n") ?: $message, 160);
    }
}
