<?php

namespace Tests\Support;

use Djneo92nl\BeoMozart\Http\HttpTransportInterface;

/** A Mozart device in memory: GETs answer what was seeded, null (like the real client) when nothing was. */
class FakeMozartTransport implements HttpTransportInterface
{
    /** @var string[] "METHOD path" */
    public array $requests = [];

    /** @var array<int, array> */
    public array $bodies = [];

    /** @var array<string, array|null> */
    private array $routes = [];

    public function seed(string $path, ?array $response): static
    {
        $this->routes[$path] = $response;

        return $this;
    }

    public function request(string $method, string $path, array $body = [], array $query = []): ?array
    {
        $this->requests[] = "{$method} {$path}";
        if ($method !== 'GET') {
            $this->bodies[] = $body;

            return null;
        }

        return $this->routes[$path] ?? null;
    }
}
