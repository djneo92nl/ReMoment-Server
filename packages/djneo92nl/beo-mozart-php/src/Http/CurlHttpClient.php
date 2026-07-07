<?php

namespace Djneo92nl\BeoMozart\Http;

use Djneo92nl\BeoMozart\Exceptions\MozartApiException;

class CurlHttpClient implements HttpTransportInterface
{
    protected string $baseUrl;

    public function __construct(string $host, int $port = 8080, string $scheme = 'http')
    {
        $this->baseUrl = "{$scheme}://{$host}:{$port}";
    }

    public function request(string $method, string $path, array $body = [], array $query = []): ?array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl.'/'.ltrim($path, '/');

        if (!empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);

        if ($method !== 'GET' && !empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $curlError = curl_errno($ch) ? curl_error($ch) : null;
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== null) {
            if ($method === 'GET') {
                return null;
            }

            throw new MozartApiException("HTTP request failed: {$curlError}");
        }

        $decoded = is_string($response) && $response !== '' ? json_decode($response, true) : null;

        if ($status >= 400) {
            $errorCode = $decoded['errorCode'] ?? null;
            $errorId = $decoded['errorId'] ?? null;
            $errorMessage = $decoded['errorMessage'] ?? null;

            if ($method === 'GET') {
                return null;
            }

            throw new MozartApiException(
                $errorMessage ?? "Mozart API returned HTTP {$status} for {$method} {$path}",
                $errorCode,
                $errorId,
                $errorMessage,
            );
        }

        return is_array($decoded) ? $decoded : null;
    }
}
