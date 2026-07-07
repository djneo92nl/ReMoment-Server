<?php

namespace Djneo92nl\BeoMozart\Http;

interface HttpTransportInterface
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    public function request(string $method, string $path, array $body = [], array $query = []): ?array;
}
