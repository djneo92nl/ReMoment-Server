<?php

namespace App\Integrations\Common;

class HttpConnector
{
    protected string $baseUrl;

    protected array $defaultHeaders;

    public function __construct(string $baseUrl, array $defaultHeaders = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultHeaders = $defaultHeaders;
    }

    public function get(string $path, array $query = []): mixed
    {
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        try {
            $response = $this->request('GET', $url);
        } catch (\Exception) {
            $response = [];
        }

        return $response;
    }

    public function post(string $path, array $data = []): mixed
    {
        return $this->request('POST', $this->baseUrl.'/'.ltrim($path, '/'), $data);
    }

    public function put(string $path, array $data = []): mixed
    {
        return $this->request('PUT', $this->baseUrl.'/'.ltrim($path, '/'), $data);
    }

    public function delete(string $path, array $data = []): mixed
    {
        return $this->request('DELETE', $this->baseUrl.'/'.ltrim($path, '/'), $data);
    }

    /**
     * Like get()/put()/delete(), but for reads and writes where "unreachable"
     * or "rejected" must not look like "empty": throws when the device does
     * not answer or answers with an error status, and returns the decoded
     * body ([] when there is none).
     */
    public function getStrict(string $path, array $query = []): array
    {
        $url = $this->baseUrl.'/'.ltrim($path, '/');

        return $this->strict('GET', $query ? $url.'?'.http_build_query($query) : $url);
    }

    public function putStrict(string $path, array $data = []): array
    {
        return $this->strict('PUT', $this->baseUrl.'/'.ltrim($path, '/'), $data);
    }

    public function deleteStrict(string $path, array $data = []): array
    {
        return $this->strict('DELETE', $this->baseUrl.'/'.ltrim($path, '/'), $data);
    }

    protected function strict(string $method, string $url, array $data = []): array
    {
        ['status' => $status, 'body' => $body] = $this->send($method, $url, $data);
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $detail = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['error']['type'] ?? null) : null;

            throw new \RuntimeException("{$method} {$url} failed with HTTP {$status}".($detail ? ": {$detail}" : ''));
        }

        return is_array($decoded) ? $decoded : [];
    }

    protected function request(string $method, string $url, array $data = []): mixed
    {
        ['status' => $status, 'body' => $response] = $this->send($method, $url, $data);

        $decoded = json_decode($response, true);

        return $decoded ?? ['raw' => $response, 'status' => $status];
    }

    /** @return array{status: int, body: string} */
    protected function send(string $method, string $url, array $data = []): array
    {
        $ch = curl_init($url);
        $headers = array_merge($this->defaultHeaders, ['Content-Type: application/json']);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ]);

        if (in_array($method, ['POST', 'PUT', 'DELETE']) && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log(curl_error($ch));
            throw new \RuntimeException('HTTP request failed: '.curl_error($ch));
        }

        curl_close($ch);

        return ['status' => $status, 'body' => (string) $response];
    }
}
