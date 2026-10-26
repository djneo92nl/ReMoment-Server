<?php

namespace App\Domain\Health;

use App\Domain\Analysis\Tools;
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

    /** Runs every probe and stores the outcome. */
    public function refresh(): array
    {
        $results = [];

        foreach ($this->probes() as $key => [$name, $group, $probe]) {
            try {
                $check = $probe();
            } catch (ConnectionException $e) {
                $check = ServiceCheck::error('Unreachable: '.$this->short($e->getMessage()));
            } catch (\Throwable $e) {
                $check = ServiceCheck::error($this->short($e->getMessage()));
            }

            $results[$key] = ['name' => $name, 'group' => $group] + $check->toArray();
        }

        Cache::put(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'results' => $results], self::TTL * 4);

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
        if (!config('analysis.acoustid_key')) {
            return ServiceCheck::disabled('ACOUSTID_API_KEY not set: fingerprints are stored but not looked up');
        }

        // A deliberately invalid fingerprint: a valid key is answered with a fingerprint error, a bad key with its own.
        $response = $this->http()->asForm()->post('https://api.acoustid.org/v2/lookup', [
            'client' => config('analysis.acoustid_key'), 'format' => 'json', 'duration' => 1, 'fingerprint' => 'AQAA',
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
            return ServiceCheck::disabled('No Spotify app configured: enter its Client ID and secret in Settings › Spotify');
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
        if (!config('lastfm.api_key')) {
            return ServiceCheck::disabled('LASTFM_API_KEY not set');
        }

        $response = $this->http()->get('https://ws.audioscrobbler.com/2.0/', [
            'method' => 'artist.getinfo', 'artist' => 'Radiohead',
            'api_key' => config('lastfm.api_key'), 'format' => 'json',
        ]);

        return $this->byStatus($response) ?? $this->lastfmError($response->json('error'), $response->json('message'))
            ?? ServiceCheck::ok('API key accepted');
    }

    private function lastfmAccount(): ServiceCheck
    {
        $session = app(LastfmSessionService::class);

        if (!config('lastfm.api_key') || !config('lastfm.shared_secret')) {
            return ServiceCheck::disabled('LASTFM_API_KEY / LASTFM_SHARED_SECRET not set');
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
        $key = config('metadata.audiodb_key');
        $response = $this->http()->get("https://www.theaudiodb.com/api/v1/json/{$key}/artist-mb.php", ['i' => self::PROBE_MBID]);

        if ($check = $this->byStatus($response)) {
            return $check;
        }

        if (!$response->ok() || empty($response->json('artists'))) {
            return ServiceCheck::error("Key rejected or no data (HTTP {$response->status()}): check AUDIODB_API_KEY");
        }

        return $key === '123' ? ServiceCheck::ok('Working with the public test key (about 30 requests a minute)') : ServiceCheck::ok('Key accepted');
    }

    private function discogs(): ServiceCheck
    {
        $token = config('metadata.discogs_token');

        if (!$token) {
            return ServiceCheck::disabled('DISCOGS_TOKEN not set');
        }

        $response = $this->http()->withHeaders(['Authorization' => "Discogs token={$token}"])
            ->get('https://api.discogs.com/oauth/identity');

        if ($check = $this->byStatus($response)) {
            return $check;
        }

        return $response->ok()
            ? ServiceCheck::ok('Token accepted for '.$response->json('username'))
            : ServiceCheck::error('Token rejected: check DISCOGS_TOKEN');
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

    private function short(string $message): string
    {
        return \Illuminate\Support\Str::limit(strtok($message, "\n") ?: $message, 160);
    }
}
