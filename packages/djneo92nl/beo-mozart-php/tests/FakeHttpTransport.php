<?php

namespace Djneo92nl\BeoMozart\Tests;

use Djneo92nl\BeoMozart\Http\HttpTransportInterface;

class FakeHttpTransport implements HttpTransportInterface
{
    /** @var array<int, array{method: string, path: string, body: array, query: array}> */
    public array $calls = [];

    public ?array $response = [];

    public function request(string $method, string $path, array $body = [], array $query = []): ?array
    {
        $this->calls[] = compact('method', 'path', 'body', 'query');

        return $this->response;
    }

    public function lastCall(): ?array
    {
        return $this->calls[count($this->calls) - 1] ?? null;
    }
}
