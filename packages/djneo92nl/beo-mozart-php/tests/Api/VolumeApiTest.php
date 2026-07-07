<?php

namespace Djneo92nl\BeoMozart\Tests\Api;

use Djneo92nl\BeoMozart\Api\VolumeApi;
use Djneo92nl\BeoMozart\Tests\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

class VolumeApiTest extends TestCase
{
    public function test_set_level_puts_level_body(): void
    {
        $transport = new FakeHttpTransport;
        (new VolumeApi($transport))->setLevel(42);

        $this->assertSame([
            'method' => 'PUT',
            'path' => '/api/v1/sound/volume/level',
            'body' => ['level' => 42],
            'query' => [],
        ], $transport->lastCall());
    }

    public function test_set_muted_puts_muted_body(): void
    {
        $transport = new FakeHttpTransport;
        (new VolumeApi($transport))->setMuted(true);

        $this->assertSame([
            'method' => 'PUT',
            'path' => '/api/v1/sound/volume/mute',
            'body' => ['muted' => true],
            'query' => [],
        ], $transport->lastCall());
    }

    public function test_get_state_hits_sound_volume(): void
    {
        $transport = new FakeHttpTransport;
        (new VolumeApi($transport))->getState();

        $this->assertSame('GET', $transport->lastCall()['method']);
        $this->assertSame('/api/v1/sound/volume', $transport->lastCall()['path']);
    }
}
