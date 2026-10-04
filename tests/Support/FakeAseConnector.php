<?php

namespace Tests\Support;

use App\Integrations\Common\HttpConnector;

/**
 * An ASE device in memory: GET answers what was seeded, a PUT replaces the
 * value under the path's wrapper key (like the real device does), and every
 * request is recorded as "METHOD path".
 */
class FakeAseConnector extends HttpConnector
{
    /** @var string[] */
    public array $requests = [];

    /** @var array<int, array> bodies of the PUT/DELETE requests, in order */
    public array $bodies = [];

    /** @var array<string, array{0: int, 1: array}> */
    private array $routes = [];

    /** When true, PUTs are accepted (200) but change nothing. */
    public bool $ignoreWrites = false;

    public function __construct()
    {
        parent::__construct('device.test:8080');
    }

    public function seed(string $path, array $body, int $status = 200): static
    {
        $this->routes[trim($path, '/')] = [$status, $body];

        return $this;
    }

    /** A write to $path also changes the nested value read from $readPath. */
    public function onPut(string $path, callable $apply): static
    {
        $this->putHooks[trim($path, '/')] = $apply;

        return $this;
    }

    /** @var array<string, callable> */
    private array $putHooks = [];

    protected function send(string $method, string $url, array $data = []): array
    {
        $path = trim(substr($url, strlen('device.test:8080')), '/');
        $this->requests[] = "{$method} {$path}";

        if ($method !== 'GET') {
            $this->bodies[] = $data;
            if (!$this->ignoreWrites && isset($this->putHooks[$path])) {
                ($this->putHooks[$path])($data, $this);
            }
        }

        [$status, $body] = $this->routes[$path] ?? ($method === 'GET' ? [404, ['error' => ['type' => 'NOT_FOUND']]] : [200, []]);

        return ['status' => $status, 'body' => json_encode($body)];
    }

    public function route(string $path): array
    {
        return $this->routes[trim($path, '/')][1];
    }

    public function replace(string $path, array $body): void
    {
        $this->routes[trim($path, '/')] = [200, $body];
    }
}
