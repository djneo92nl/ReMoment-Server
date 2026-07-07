<?php

namespace Djneo92nl\BeoMozart\Tests\WebSocket;

use Djneo92nl\BeoMozart\WebSocket\EventClassifier;
use PHPUnit\Framework\TestCase;

class EventClassifierTest extends TestCase
{
    protected function loadFixture(string $name): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/../fixtures/'.$name),
            true
        );
    }

    public function test_classifies_playback_metadata_by_shape(): void
    {
        $fixture = $this->loadFixture('ws-playback-metadata.json');

        $this->assertSame(
            EventClassifier::PLAYBACK_METADATA,
            EventClassifier::classify($fixture['eventType'], $fixture['eventData'])
        );
    }

    public function test_classifies_playback_progress_by_shape(): void
    {
        $fixture = $this->loadFixture('ws-playback-progress.json');

        $this->assertSame(
            EventClassifier::PLAYBACK_PROGRESS,
            EventClassifier::classify($fixture['eventType'], $fixture['eventData'])
        );
    }

    public function test_classifies_volume_by_shape(): void
    {
        $fixture = $this->loadFixture('ws-volume.json');

        $this->assertSame(
            EventClassifier::VOLUME,
            EventClassifier::classify($fixture['eventType'], $fixture['eventData'])
        );
    }

    public function test_classifies_playback_state_even_with_wrong_event_type(): void
    {
        $fixture = $this->loadFixture('ws-playback-state.json');

        $this->assertSame(
            EventClassifier::PLAYBACK_STATE,
            EventClassifier::classify($fixture['eventType'], $fixture['eventData'])
        );
    }

    public function test_returns_unknown_for_unrecognized_shape(): void
    {
        $this->assertSame(
            EventClassifier::UNKNOWN,
            EventClassifier::classify('SomethingElse', ['foo' => 'bar'])
        );
    }

    public function test_returns_unknown_when_event_data_is_not_an_array(): void
    {
        $this->assertSame(
            EventClassifier::UNKNOWN,
            EventClassifier::classify('SomeTag', 'not-an-array')
        );
    }

    public function test_does_not_confuse_power_state_wrapper_with_playback_state(): void
    {
        // PowerStateEnum shares the same {value: string} shape as RenderingState
        // but with a disjoint set of values — must not be misclassified.
        $this->assertSame(
            EventClassifier::UNKNOWN,
            EventClassifier::classify('WebSocketEventPowerState', ['value' => 'networkStandby'])
        );
    }
}
