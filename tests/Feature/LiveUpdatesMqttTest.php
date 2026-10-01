<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\TrackData;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeBareDriver;
use Tests\TestCase;

class LiveUpdatesMqttTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Now-playing events also store play history, which needs the device rows.
        foreach ([7, 8] as $id) {
            Device::forceCreate([
                'id' => $id,
                'device_name' => "Device {$id}",
                'device_brand_name' => 'Test',
                'device_product_type' => 'Speaker',
                'device_driver' => FakeBareDriver::class,
                'device_driver_name' => 'Fake',
            ]);
        }
    }

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

        $this->assertSame(['{"volume":40,"muted":false}', '{"volume":42,"muted":false}'], array_column($volume, 'message'));
        $this->assertTrue($volume[0]['retain']);
    }

    private function messagesOn(string $topic): array
    {
        return array_column(array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === $topic)), 'message');
    }

    public function test_volume_payload_carries_mute_and_publishes_on_mute_changes(): void
    {
        event(new VolumeUpdated(deviceId: '7', volume: 40, muted: false));
        event(new VolumeUpdated(deviceId: '7', volume: 40, muted: true));
        event(new VolumeUpdated(deviceId: '7', volume: 40, muted: true));
        // A listener that doesn't know the mute state keeps the last known one.
        event(new VolumeUpdated(deviceId: '7', volume: 41));

        $this->assertSame(
            ['{"volume":40,"muted":false}', '{"volume":40,"muted":true}', '{"volume":41,"muted":true}'],
            $this->messagesOn('remoment/player/7/volume')
        );
    }

    private function nowPlaying(string $track = 'Song'): NowPlaying
    {
        return new NowPlaying(track: new TrackData(name: $track, artist: new ArtistData(name: 'Artist')), type: 'music', platform: 'media');
    }

    public function test_data_is_retained_and_not_repeated(): void
    {
        event(new NowPlayingUpdated('7', $this->nowPlaying(), 'media'));
        event(new NowPlayingUpdated('7', $this->nowPlaying(), 'media'));
        event(new NowPlayingUpdated('7', $this->nowPlaying('Next'), 'media'));

        $data = array_values(array_filter($this->mqtt->published, fn ($p) => $p['topic'] === 'remoment/player/7/data'));
        $this->assertSame(['Song', 'Next'], array_map(fn ($p) => json_decode($p['message'], true)['track'], $data));
        $this->assertTrue($data[0]['retain']);
    }

    public function test_standby_and_unreachable_clear_data_with_an_empty_retained_payload(): void
    {
        event(new NowPlayingUpdated('7', $this->nowPlaying(), 'media'));
        event(new NowPlayingEnded('7'));

        $last = collect($this->mqtt->published)->where('topic', 'remoment/player/7/data')->last();
        $this->assertSame(['topic' => 'remoment/player/7/data', 'message' => '', 'retain' => true], $last);

        $this->mqtt->published = [];
        DeviceCache::updateState(8, State::Playing);
        DeviceCache::updateState(8, State::Unreachable);
        $this->assertSame([''], $this->messagesOn('remoment/player/8/data'));
    }

    public function test_pausing_keeps_data(): void
    {
        event(new NowPlayingUpdated('7', $this->nowPlaying(), 'media'));
        DeviceCache::updateState(7, State::Paused);
        DeviceCache::updateState(7, State::Playing);

        $this->assertCount(1, $this->messagesOn('remoment/player/7/data'));
    }

    public function test_data_is_republished_when_a_device_recovers_with_the_same_track(): void
    {
        event(new NowPlayingUpdated('7', $this->nowPlaying(), 'media'));
        DeviceCache::updateState(7, State::Unreachable);
        // The listener is back and only reports progress: it already announced this track.
        event(new ProgressUpdated(deviceId: '7', progress: 30));

        $messages = $this->messagesOn('remoment/player/7/data');
        $this->assertCount(3, $messages);
        $this->assertSame('', $messages[1]);
        $this->assertSame('Song', json_decode($messages[2], true)['track']);
    }

    public function test_a_new_track_after_standby_is_published_once(): void
    {
        DeviceCache::updateState(7, State::Standby);
        event(new NowPlayingUpdated('7', $this->nowPlaying('Fresh'), 'media'));

        $messages = $this->messagesOn('remoment/player/7/data');
        $this->assertSame(['', 'Fresh'], array_map(fn ($m) => $m === '' ? '' : json_decode($m, true)['track'], $messages));
    }
}
