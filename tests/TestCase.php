<?php

namespace Tests;

use App\Services\MqttService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeMqttService;

abstract class TestCase extends BaseTestCase
{
    protected FakeMqttService $mqtt;

    protected function setUp(): void
    {
        parent::setUp();

        // State changes publish to MQTT; never reach a real broker from tests.
        $this->mqtt = new FakeMqttService;
        $this->app->instance(MqttService::class, $this->mqtt);
    }
}
