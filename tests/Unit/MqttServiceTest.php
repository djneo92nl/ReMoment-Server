<?php

namespace Tests\Unit;

use App\Services\MqttService;
use Mockery;
use PhpMqtt\Client\MqttClient;
use PHPUnit\Framework\TestCase;

class MqttServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function service(array $clients): MqttService
    {
        return new class($clients) extends MqttService
        {
            public array $made = [];

            public function __construct(private array $queue) {}

            public function idleFor(int $seconds): void
            {
                $this->lastPublishAt = microtime(true) - $seconds;
            }

            protected function client(): MqttClient
            {
                if ($this->client === null) {
                    $this->client = array_shift($this->queue);
                    $this->made[] = $this->client;
                }

                return $this->client;
            }
        };
    }

    public function test_a_connection_is_reused_while_it_is_busy(): void
    {
        $client = Mockery::mock(MqttClient::class);
        $client->shouldReceive('publish')->twice();
        $client->shouldReceive('disconnect')->zeroOrMoreTimes();

        $mqtt = $this->service([$client]);
        $mqtt->publish('a', '1');
        $mqtt->publish('a', '2');

        $this->assertCount(1, $mqtt->made);
    }

    public function test_an_idle_connection_is_replaced_before_publishing(): void
    {
        // A long pause: the broker may already have dropped the silent connection, and a
        // QoS 0 publish into it would be lost without an error.
        $stale = Mockery::mock(MqttClient::class);
        $stale->shouldReceive('publish')->once();
        $stale->shouldReceive('disconnect')->atLeast()->once();
        $fresh = Mockery::mock(MqttClient::class);
        $fresh->shouldReceive('publish')->once()->with('remoment/player/5/state', '{"state":"playing"}', 0, true);
        $fresh->shouldReceive('disconnect')->zeroOrMoreTimes();

        $mqtt = $this->service([$stale, $fresh]);
        $mqtt->publish('remoment/player/5/progress', '40');
        $mqtt->idleFor(120);
        $mqtt->publish('remoment/player/5/state', '{"state":"playing"}', retain: true);

        $this->assertSame([$stale, $fresh], $mqtt->made);
    }
}
