<?php

namespace Tests\Unit\Mozart;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Events\Device\VolumeUpdated;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use Remoment\MozartDriver\Services\DeviceListener;
use Tests\TestCase;

class DeviceListenerTest extends TestCase
{
    public function test_build_now_playing_maps_metadata_fields(): void
    {
        $listener = new DeviceListener('127.0.0.1');

        $nowPlaying = $listener->buildNowPlaying([
            'title' => 'Track Name',
            'artistName' => 'Artist Name',
            'albumName' => 'Album Name',
            'totalDurationSeconds' => 210,
            'source' => 'spotify',
            'art' => [['url' => 'https://example.test/art.jpg']],
        ]);

        $this->assertSame('music', $nowPlaying->type);
        $this->assertSame('media', $nowPlaying->platform);
        $this->assertSame('Track Name', $nowPlaying->track?->name);
        $this->assertSame('Artist Name', $nowPlaying->track?->artist?->name);
        $this->assertSame('Album Name', $nowPlaying->album?->name);
        $this->assertSame(210, $nowPlaying->track?->duration);
        $this->assertSame(['https://example.test/art.jpg'], $nowPlaying->track?->images);
        $this->assertNull($nowPlaying->radio);
    }

    public function test_build_now_playing_marks_radio_platform_after_net_radio_source_change(): void
    {
        $listener = new DeviceListener('127.0.0.1');

        $this->callProtected($listener, 'applyEvent', [
            'WebSocketEventSourceChange',
            ['id' => 'radio-1', 'isMultiroomAvailable' => true, 'type' => ['value' => 'netRadio']],
            '1',
        ]);

        $nowPlaying = $listener->buildNowPlaying([
            'title' => 'Radio One',
            'containerName' => 'Radio One',
        ]);

        $this->assertSame('radio', $nowPlaying->platform);
        $this->assertSame('Radio One', $nowPlaying->radio?->name);
    }

    public function test_apply_event_dispatches_now_playing_updated_for_playback_metadata(): void
    {
        Event::fake();

        $listener = new DeviceListener('127.0.0.1');

        $this->callProtected($listener, 'applyEvent', [
            'WebSocketEventPlaybackMetadata',
            ['title' => 'Track Name', 'artistName' => 'Artist Name'],
            '1',
        ]);

        Event::assertDispatched(NowPlayingUpdated::class, function (NowPlayingUpdated $event) {
            return $event->deviceId === '1'
                && $event->sourceType === 'media'
                && $event->nowPlaying->track?->name === 'Track Name';
        });
    }

    public function test_apply_event_dispatches_progress_updated(): void
    {
        Event::fake();

        $listener = new DeviceListener('127.0.0.1');

        $this->callProtected($listener, 'applyEvent', [
            'WebSocketEventPlaybackProgress',
            ['id' => 'abc', 'progress' => 42, 'totalDuration' => 210],
            '1',
        ]);

        Event::assertDispatched(ProgressUpdated::class, function (ProgressUpdated $event) {
            return $event->deviceId === '1' && $event->progress === 42;
        });
    }

    public function test_apply_event_dispatches_volume_updated(): void
    {
        Event::fake();

        $listener = new DeviceListener('127.0.0.1');

        $this->callProtected($listener, 'applyEvent', [
            'WebSocketEventVolume',
            ['default' => ['level' => 30], 'level' => ['level' => 55], 'maximum' => ['level' => 90], 'muted' => ['muted' => false]],
            '1',
        ]);

        Event::assertDispatched(VolumeUpdated::class, function (VolumeUpdated $event) {
            return $event->deviceId === '1' && $event->volume === 55;
        });
    }

    public function test_apply_event_marks_playing_on_started_rendering_state(): void
    {
        Event::fake();

        $listener = new DeviceListener('127.0.0.1');

        $this->callProtected($listener, 'applyEvent', [
            'WebSocketEventPlaybackState',
            ['value' => 'started'],
            '1',
        ]);

        $this->assertSame(State::Playing, DeviceCache::getState('1'));
        Event::assertNotDispatched(NowPlayingEnded::class);
    }

    public function test_apply_event_marks_paused_on_paused_rendering_state(): void
    {
        $listener = new DeviceListener('127.0.0.1');

        $this->callProtected($listener, 'applyEvent', [
            'WebSocketEventPlaybackState',
            ['value' => 'paused'],
            '1',
        ]);

        $this->assertSame(State::Paused, DeviceCache::getState('1'));
    }

    public function test_apply_event_ends_playback_on_idle_rendering_state(): void
    {
        Event::fake();

        $listener = new DeviceListener('127.0.0.1');

        $this->callProtected($listener, 'applyEvent', [
            'WebSocketEventPlaybackState',
            ['value' => 'idle'],
            '1',
        ]);

        $this->assertSame(State::Standby, DeviceCache::getState('1'));
        Event::assertDispatched(NowPlayingEnded::class, fn (NowPlayingEnded $event) => $event->deviceId === '1');
    }

    public function test_apply_event_ignores_unrecognized_event_without_crashing(): void
    {
        Event::fake();

        $listener = new DeviceListener('127.0.0.1');

        $this->callProtected($listener, 'applyEvent', [
            'SomeCompletelyUnknownTag',
            ['foo' => 'bar'],
            '1',
        ]);

        // Log::info() itself dispatches a MessageLogged event under
        // Event::fake(), so assert no *domain* event fired rather than
        // nothing at all.
        Event::assertNotDispatched(NowPlayingUpdated::class);
        Event::assertNotDispatched(ProgressUpdated::class);
        Event::assertNotDispatched(NowPlayingEnded::class);
        Event::assertNotDispatched(VolumeUpdated::class);
    }

    public function test_apply_event_resolves_event_kind_by_shape_when_literal_tag_is_wrong(): void
    {
        Event::fake();

        $listener = new DeviceListener('127.0.0.1');

        // Deliberately wrong eventType — must still be classified correctly
        // by EventClassifier's structural fallback in resolveEventKind().
        $this->callProtected($listener, 'applyEvent', [
            'SomeUnexpectedTag',
            ['progress' => 10, 'totalDuration' => 200],
            '1',
        ]);

        Event::assertDispatched(ProgressUpdated::class, fn (ProgressUpdated $event) => $event->progress === 10);
    }

    private function callProtected(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $args);
    }
}
