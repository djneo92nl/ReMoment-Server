<?php

namespace Djneo92nl\BeoMozart\Api;

use Djneo92nl\BeoMozart\Http\HttpTransportInterface;

class SourcesApi
{
    public function __construct(protected HttpTransportInterface $transport) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $response = $this->transport->request('GET', '/api/v1/playback/sources');

        return $response['items'] ?? [];
    }

    public function activate(string $sourceId): void
    {
        $this->transport->request('POST', "/api/v1/playback/sources/active/{$sourceId}");
    }
}
