<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Events\Device\VolumeUpdated;
use Tests\TestCase;

class LiveUpdatesMqttTest extends TestCase
{
    private function statePublishes(): array
    {
        return array_values(array_filter($this->mqtt->published, fn ($p) => str_ends_with($p['topic'], '/state')));
    }

    public function test_state_transitions_publish_a_retained_state_message(): void
    {
        DeviceCache::updateState(7, State::Playing);

        $this->assertSame([[
            'topic' => 'remoment/player/7/state',
            'message' => '{"state":"playing"}',
            'retain' => true,
        ]], $this->statePublishes());
    }

    public function test_repeated_state_writes_do_not_republish(): void
    {
        DeviceCache::updateState(7, State::Playing);
        DeviceCache::updateState(7, State::Playing);
        DeviceCache::updateState(7, State::Playing);
        DeviceCache::updateState(7, State::Paused);

        $this->assertSame(
            ['{"state":"playing"}', '{"state":"paused"}'],
            array_column($this->statePublishes(), 'message')
        );
    }

    public function test_volume_changes_publish_once_per_level(): void
    {
        event(new VolumeUpdated(deviceId: '7', volume: 40));
        event(new VolumeUpdated(deviceId: '7', volume: 40));
        event(new VolumeUpdated(deviceId: '7', volume: 42));

        $volume = array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === 'remoment/player/7/volume'));

        $this->assertSame(['{"volume":40}', '{"volume":42}'], array_column($volume, 'message'));
        $this->assertTrue($volume[0]['retain']);
    }
}
