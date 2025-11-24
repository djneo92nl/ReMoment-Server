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

        return $this->request('GET', $url);
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

    protected function request(string $method, string $url, array $data = []): mixed
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
            throw new \RuntimeException('HTTP request failed: '.curl_error($ch));
        }

        curl_close($ch);

        $decoded = json_decode($response, true);

        return $decoded ?? ['raw' => $response, 'status' => $status];
    }
}
