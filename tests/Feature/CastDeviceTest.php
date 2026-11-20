<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\DeviceModels;
use App\Domain\Device\State;
use App\Domain\SubDevices\DeviceLinks;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Events\Device\ProgressUpdated;
use App\Integrations\Cast\CastConnection;
use App\Integrations\Cast\CastNowPlaying;
use App\Integrations\Cast\MusicPlayerDriver;
use App\Integrations\Cast\Services\DeviceListener;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeCastConnection;
use Tests\Support\FakeLinkedDriver;
use Tests\TestCase;

/** The Chromecast as a device (workstream 2a, step 3): its listener reads what is casting, its driver sends the transport. */
class CastDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const APP = ['appId' => 'CC1AD845', 'displayName' => 'Netflix', 'transportId' => 'web-1'];

    private Device $dongle;

    protected function setUp(): void
    {
        parent::setUp();

        FakeCastConnection::reset();
        $this->app->bind(CastConnection::class, fn ($app, array $params) => new FakeCastConnection($params['host'], timeout: $params['timeout'] ?? 5.0));
        $this->dongle = Device::factory()->create([
            'device_name' => 'Chromecast', 'ip_address' => '10.0.0.40', 'device_brand_name' => 'Google', 'device_product_type' => 'Chromecast',
            'device_driver' => MusicPlayerDriver::class, 'device_driver_name' => 'Cast',
        ]);
    }

    private function listener(array &$reading): DeviceListener
    {
        return new DeviceListener('10.0.0.40', function () use (&$reading) {
            if ($reading === ['throw']) {
                throw new \RuntimeException('Cast receiver is not reachable');
            }

            return $reading;
        });
    }

    private function playing(string $title = 'Episode 1', string $state = 'PLAYING', float $time = 12.0): array
    {
        $media = [['playerState' => $state, 'currentTime' => $time, 'media' => ['contentId' => 'c', 'duration' => 3000, 'metadata' => ['title' => $title, 'seriesTitle' => 'The Show']]]];

        return ['now' => CastNowPlaying::fromStatus(['applications' => [self::APP]], $media), 'standby' => false, 'external' => false];
    }

    public function test_the_chromecast_is_a_model_that_has_no_card_of_its_own_and_lists_its_transport(): void
    {
        $this->assertSame(MusicPlayerDriver::class, DeviceModels::find('Google', 'Chromecast')['driver']);
        $this->assertFalse(DeviceModels::standalone($this->dongle));
        $this->assertSame(DeviceListener::class, config('devices.listeners')[MusicPlayerDriver::class]);

        DeviceCache::updateState($this->dongle->id, State::Standby);
        $caps = $this->getJson("/api/devices/{$this->dongle->id}")->json('data.capabilities');
        $this->assertSame(['media_controls', 'seek'], $caps, 'No volume, power, sources or queue of its own: the TV has those.');
    }

    public function test_a_cast_session_is_reported_with_its_title_state_and_progress(): void
    {
        $reading = $this->playing();
        $listener = $this->listener($reading);
        $updates = $progress = 0;
        Event::listen(NowPlayingUpdated::class, function () use (&$updates) {
            $updates++;
        });
        Event::listen(ProgressUpdated::class, function () use (&$progress) {
            $progress++;
        });

        $listener->poll((string) $this->dongle->id);

        $now = DeviceCache::getNowPlaying($this->dongle->id);
        $this->assertSame('Episode 1', $now->track->name);
        $this->assertSame('Netflix', $now->source->name);
        $this->assertSame(State::Playing, DeviceCache::getState($this->dongle->id));

        // The same status again raises nothing new; a moved position is progress.
        $listener->poll((string) $this->dongle->id);
        $reading = $this->playing(time: 20.0);
        $listener->poll((string) $this->dongle->id);

        $this->assertSame(1, $updates);
        $this->assertSame(2, $progress);
    }

    public function test_a_paused_session_is_paused_and_a_new_title_is_a_new_now_playing(): void
    {
        $reading = $this->playing(state: 'PAUSED');
        $listener = $this->listener($reading);

        $listener->poll((string) $this->dongle->id);
        $this->assertSame(State::Paused, DeviceCache::getState($this->dongle->id));

        $reading = $this->playing('Episode 2');
        $listener->poll((string) $this->dongle->id);
        $this->assertSame('Episode 2', DeviceCache::getNowPlaying($this->dongle->id)->track->name);
        $this->assertSame(State::Playing, DeviceCache::getState($this->dongle->id));
    }

    public function test_the_idle_backdrop_standby_and_no_app_are_not_a_session(): void
    {
        $ambient = ['now' => CastNowPlaying::fromStatus(['applications' => [['appId' => 'E8C28D3C', 'displayName' => 'Backdrop', 'isIdleScreen' => true]]], []), 'standby' => false, 'external' => false];
        $reading = $ambient;
        $listener = $this->listener($reading);
        $ended = 0;
        Event::listen(NowPlayingEnded::class, function () use (&$ended) {
            $ended++;
        });

        // The first look at a receiver that shows its backdrop: reachable, idle.
        $listener->poll((string) $this->dongle->id);
        $this->assertSame(State::Standby, DeviceCache::getState($this->dongle->id));
        $this->assertNull(DeviceCache::getNowPlaying($this->dongle->id));

        $reading = $this->playing();
        $listener->poll((string) $this->dongle->id);
        $this->assertSame(State::Playing, DeviceCache::getState($this->dongle->id));

        $reading = $ambient;
        $listener->poll((string) $this->dongle->id);
        $this->assertSame(State::Standby, DeviceCache::getState($this->dongle->id));
        $this->assertNull(DeviceCache::getNowPlaying($this->dongle->id));

        // Standby with an app still listed, and a receiver with no app at all, end nothing that is not playing.
        $reading = ['now' => null, 'standby' => true, 'external' => false];
        $listener->poll((string) $this->dongle->id);
        $reading = ['now' => null, 'standby' => false, 'external' => false];
        $listener->poll((string) $this->dongle->id);

        $this->assertSame(1, $ended);
    }

    public function test_an_unreachable_receiver_throws_so_the_listener_backs_off(): void
    {
        $reading = ['throw'];
        $listener = $this->listener($reading);

        $this->expectException(\RuntimeException::class);
        $listener->poll((string) $this->dongle->id);
    }

    public function test_the_listener_is_made_for_a_device_with_an_address_only(): void
    {
        $this->assertNotNull(DeviceListener::forDevice($this->dongle));
        $this->assertNull(DeviceListener::forDevice(new Device(['device_name' => 'No address'])));
    }

    public function test_the_transport_commands_go_to_the_media_session_of_the_running_app(): void
    {
        DeviceCache::updateState($this->dongle->id, State::Playing);

        $this->postJson("/api/devices/{$this->dongle->id}/pause")->assertOk();
        $this->postJson("/api/devices/{$this->dongle->id}/play")->assertOk();
        $this->postJson("/api/devices/{$this->dongle->id}/stop")->assertOk();
        $this->postJson("/api/devices/{$this->dongle->id}/next")->assertOk();
        $this->postJson("/api/devices/{$this->dongle->id}/previous")->assertOk();
        $this->putJson("/api/devices/{$this->dongle->id}/seek", ['position' => 90])->assertOk();

        $sent = array_map(fn (array $r) => [$r[0], $r[1], $r[2]], FakeCastConnection::$requests);
        $this->assertSame(['PAUSE', 'PLAY', 'STOP', 'QUEUE_NEXT', 'QUEUE_PREV', 'SEEK'], array_column(array_column($sent, 2), 'type'));
        $this->assertSame(['web-1', CastConnection::NS_MEDIA, ['type' => 'SEEK', 'mediaSessionId' => 7, 'currentTime' => 90]], $sent[5]);
    }

    public function test_nothing_casting_is_unsupported_not_a_crash(): void
    {
        DeviceCache::updateState($this->dongle->id, State::Standby);

        FakeCastConnection::$receiver = ['applications' => []];
        $this->postJson("/api/devices/{$this->dongle->id}/pause")->assertStatus(422)->assertJsonPath('error', 'unsupported')->assertJsonPath('message', 'Nothing is casting.');

        FakeCastConnection::$receiver = ['applications' => [self::APP]];
        FakeCastConnection::$media = [];
        $this->postJson("/api/devices/{$this->dongle->id}/pause")->assertStatus(422)->assertJsonPath('error', 'unsupported');

        $this->assertSame([], FakeCastConnection::$requests);
    }

    public function test_a_tv_that_has_the_dongle_routes_its_transport_to_it_while_the_input_is_selected(): void
    {
        $tv = Device::factory()->create(['device_name' => 'V1', 'device_driver' => FakeLinkedDriver::class]);
        DeviceLinks::add($this->dongle, $tv, 'input', 'hdmi_1');
        DeviceCache::updateState($tv->id, State::Playing);
        \App\Domain\SubDevices\ActiveInput::put($tv->id, new \App\Domain\Media\Source(name: 'SET-TOP BOX', category: 'video', jid: 'hdmi_1', sourceType: 'HDMI', connector: '1'));

        $this->postJson("/api/devices/{$tv->id}/pause")->assertOk();

        $this->assertSame('PAUSE', FakeCastConnection::$requests[0][2]['type']);
    }

    public function test_a_cast_the_listener_sees_wakes_the_tv_on_its_input_and_the_backdrop_does_not(): void
    {
        FakeLinkedDriver::reset();
        $tv = Device::factory()->create(['device_name' => 'V1', 'device_driver' => FakeLinkedDriver::class]);
        DeviceLinks::add($this->dongle, $tv, 'input', 'hdmi_1');
        DeviceCache::updateState($tv->id, State::Standby);
        DeviceCache::updateState($this->dongle->id, State::Standby);

        // The dongle shows its idle backdrop: nothing to follow.
        $reading = ['now' => CastNowPlaying::fromStatus(['applications' => [['appId' => 'E8C28D3C', 'displayName' => 'Backdrop', 'isIdleScreen' => true]]], []), 'standby' => false, 'external' => false];
        $listener = $this->listener($reading);
        $listener->poll((string) $this->dongle->id);
        $this->assertSame([], FakeLinkedDriver::callsOn($tv->id));

        // A phone casts to it: the V1 is woken and put on HDMI 1, a few seconds (the debounce) later.
        $reading = $this->playing();
        $listener->poll((string) $this->dongle->id);

        $this->assertSame([['activateSource', 'hdmi_1']], FakeLinkedDriver::callsOn($tv->id));
    }

    public function test_the_hardware_page_lists_it_as_fake_tested(): void
    {
        $this->artisan('hardware:table', ['--check' => true])->assertExitCode(0);
        $this->assertStringContainsString('Chromecast', file_get_contents(base_path('docs/user/hardware.md')));
    }
}
