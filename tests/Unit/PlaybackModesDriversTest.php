<?php

namespace Tests\Unit;

use App\Domain\Device\RepeatMode;
use App\Events\Device\PlaybackModesUpdated;
use App\Integrations\Sonos\PlayMode;
use App\Integrations\Spotify\MusicPlayerDriver as SpotifyDriver;
use App\Integrations\Spotify\Services\DeviceListener as SpotifyListener;
use App\Services\SpotifyTokenService;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use Remoment\MozartDriver\MusicPlayerDriver as MozartDriver;
use Remoment\MozartDriver\Services\DeviceListener as MozartListener;
use Tests\TestCase;

/** How each driver maps its own shuffle/repeat vocabulary onto PlaybackModes. */
class PlaybackModesDriversTest extends TestCase
{
    public function test_sonos_play_modes_round_trip(): void
    {
        $this->assertSame([false, RepeatMode::Off], PlayMode::parse('NORMAL'));
        $this->assertSame([true, RepeatMode::All], PlayMode::parse('SHUFFLE'));
        $this->assertSame([true, RepeatMode::Off], PlayMode::parse('SHUFFLE_NOREPEAT'));
        $this->assertSame([false, RepeatMode::One], PlayMode::parse('REPEAT_ONE'));
        $this->assertSame([null, null], PlayMode::parse('SOMETHING_NEW'));

        foreach (['NORMAL', 'REPEAT_ALL', 'REPEAT_ONE', 'SHUFFLE_NOREPEAT', 'SHUFFLE', 'SHUFFLE_REPEAT_ONE'] as $mode) {
            $this->assertSame($mode, PlayMode::build(...PlayMode::parse($mode)));
        }
    }

    public function test_spotify_repeat_states(): void
    {
        $this->assertSame('context', SpotifyDriver::spotifyRepeatState(RepeatMode::All));
        $this->assertSame('track', SpotifyDriver::spotifyRepeatState(RepeatMode::One));
        $this->assertSame(RepeatMode::Off, SpotifyDriver::repeatModeFromSpotify('off'));
        $this->assertNull(SpotifyDriver::repeatModeFromSpotify(null));
    }

    public function test_spotify_modes_from_playback(): void
    {
        $listener = new SpotifyListener((new ReflectionClass(SpotifyTokenService::class))->newInstanceWithoutConstructor());

        $modes = $listener->modesFromPlayback(['shuffle_state' => true, 'repeat_state' => 'track'], liked: false);

        $this->assertSame(['shuffle' => true, 'repeat' => 'one', 'liked' => false], $modes->toArray());
    }

    public function test_mozart_queue_settings_event_reports_modes(): void
    {
        Event::fake([PlaybackModesUpdated::class]);
        $listener = new MozartListener('127.0.0.1');

        $method = (new ReflectionClass($listener))->getMethod('applyEvent');
        $method->invoke($listener, 'WebSocketEventPlayQueueSettings', ['shuffle' => true, 'repeat' => 'none'], '5');

        Event::assertDispatched(PlaybackModesUpdated::class, fn ($e) => $e->deviceId === '5'
            && $e->modes->toArray() === ['shuffle' => true, 'repeat' => 'off', 'liked' => null]);
        $this->assertSame('track', MozartDriver::mozartRepeat(RepeatMode::One));
    }
}
