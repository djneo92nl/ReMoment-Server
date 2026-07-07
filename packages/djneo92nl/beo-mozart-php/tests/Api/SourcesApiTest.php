<?php

namespace Djneo92nl\BeoMozart\Tests\Api;

use Djneo92nl\BeoMozart\Api\SourcesApi;
use Djneo92nl\BeoMozart\Tests\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

class SourcesApiTest extends TestCase
{
    public function test_list_returns_items_array(): void
    {
        $transport = new FakeHttpTransport;
        $transport->response = ['items' => [['id' => 'source-1']]];

        $sources = (new SourcesApi($transport))->list();

        $this->assertSame('GET', $transport->lastCall()['method']);
        $this->assertSame('/api/v1/playback/sources', $transport->lastCall()['path']);
        $this->assertSame([['id' => 'source-1']], $sources);
    }

    public function test_list_defaults_to_empty_array_when_no_items(): void
    {
        $transport = new FakeHttpTransport;
        $transport->response = null;

        $this->assertSame([], (new SourcesApi($transport))->list());
    }

    public function test_activate_posts_to_active_source_by_id(): void
    {
        $transport = new FakeHttpTransport;
        (new SourcesApi($transport))->activate('source-1');

        $this->assertSame([
            'method' => 'POST',
            'path' => '/api/v1/playback/sources/active/source-1',
            'body' => [],
            'query' => [],
        ], $transport->lastCall());
    }
}
