<?php

namespace Tests\Support;

use App\Services\MqttService;

/** Records publishes instead of talking to a broker. Bound for every test in Tests\TestCase. */
class FakeMqttService extends MqttService
{
    /** @var array<int, array{topic: string, message: string, retain: bool}> */
    public array $published = [];

    public function publish(string $topic, string $message, int $qualityOfService = 0, bool $retain = false): void
    {
        $this->published[] = compact('topic', 'message', 'retain');
    }

    public function disconnect(): void {}

    public function topics(): array
    {
        return array_column($this->published, 'topic');
    }
}
