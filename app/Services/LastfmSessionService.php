<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class LastfmSessionService
{
    private const API_URL = 'https://ws.audioscrobbler.com/2.0/';

    public function getAuthorizationUrl(): string
    {
        return 'https://www.last.fm/api/auth/?'.http_build_query([
            'api_key' => config('lastfm.api_key'),
            'cb' => route('lastfm.callback'),
        ]);
    }

    public function handleCallback(string $token): void
    {
        $response = $this->signedRequest([
            'method' => 'auth.getsession',
            'token' => $token,
        ]);

        $sessionKey = $response['session']['key'] ?? null;

        if (!$sessionKey) {
            throw new \RuntimeException('Last.fm did not return a session key.');
        }

        Setting::set('lastfm_session_key', $sessionKey);
        Setting::set('lastfm_username', $response['session']['name'] ?? '');
    }

    public function isConnected(): bool
    {
        return Setting::get('lastfm_session_key') !== null;
    }

    public function username(): ?string
    {
        return Setting::get('lastfm_username');
    }

    public function disconnect(): void
    {
        Setting::forget('lastfm_session_key');
        Setting::forget('lastfm_username');
    }

    /**
     * Sign and send a request against the authenticated Last.fm API.
     *
     * @param  array<string, string|int>  $params
     */
    public function signedRequest(array $params): array
    {
        $params['api_key'] = config('lastfm.api_key');

        $sessionKey = Setting::get('lastfm_session_key');
        if ($sessionKey !== null && !isset($params['sk'])) {
            $params['sk'] = $sessionKey;
        }

        $params['api_sig'] = $this->sign($params);
        $params['format'] = 'json';

        $response = Http::withHeaders(['User-Agent' => 'ReMoment/1.0 (remko@pionect.nl)'])
            ->asForm()
            ->post(self::API_URL, $params);

        if ($response->failed()) {
            throw new \RuntimeException("Last.fm request failed ({$params['method']}): HTTP {$response->status()}");
        }

        $data = $response->json() ?? [];

        if (isset($data['error'])) {
            throw new \RuntimeException("Last.fm API error {$data['error']}: ".($data['message'] ?? 'unknown error'));
        }

        return $data;
    }

    /**
     * @param  array<string, string|int>  $params
     */
    private function sign(array $params): string
    {
        ksort($params);

        $signature = '';
        foreach ($params as $key => $value) {
            $signature .= $key.$value;
        }

        return md5($signature.config('lastfm.shared_secret'));
    }
}
