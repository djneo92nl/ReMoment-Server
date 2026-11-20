<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\Source;
use App\Domain\SubDevices\DeviceLinks;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Models\Device;
use App\Models\DeviceLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeLinkedDriver;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/**
 * Workstream 2a, step 2: the actions of a link (meta.on_activate / on_deactivate), run by the host's events whoever
 * changed its input. The queue is `sync` in tests, so a dispatched job has run when the event returns.
 */
class SubDeviceLinkActionsTest extends TestCase
{
    use RefreshDatabase;

    private Device $player;

    private Device $changer;

    private DeviceLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        FakeLinkedDriver::reset();

        // A CD changer on a player's line-in, controlled by an ESP: the line-in switches it on, leaving it off.
        $this->player = Device::factory()->create(['device_name' => 'Moment', 'device_driver' => FakeLinkedDriver::class]);
        $this->changer = Device::factory()->create(['device_name' => 'CD changer', 'device_driver' => FakeLinkedDriver::class]);
        $this->link = DeviceLinks::add($this->changer, $this->player, 'source', 'LINE IN', [
            'on_activate' => [['action' => 'power_on'], ['action' => 'source', 'source_id' => 'CD']],
            'on_deactivate' => [['action' => 'power_off']],
        ]);
    }

    private function lineIn(): Source
    {
        return new Source(name: 'Line In', category: 'MUSIC', jid: 'LINE IN', sourceType: 'LINE IN');
    }

    private function selects(Source $source): void
    {
        event(new NowPlayingUpdated((string) $this->player->id, new NowPlaying(type: 'video', platform: 'media', source: $source), 'media'));
    }

    public function test_selecting_the_input_runs_the_activate_actions_on_the_sub_device(): void
    {
        $this->selects($this->lineIn());

        $this->assertSame([['powerOn', null], ['activateSource', 'CD']], FakeLinkedDriver::callsOn($this->changer->id));
        $this->assertSame([], FakeLinkedDriver::callsOn($this->player->id));
    }

    public function test_leaving_the_input_runs_the_deactivate_actions(): void
    {
        $this->selects($this->lineIn());
        FakeLinkedDriver::$log = [];

        $this->selects(new Source(name: 'Spotify', category: 'MUSIC', jid: 'spotify:1', sourceType: 'SPOTIFY'));

        $this->assertSame([['standby', null]], FakeLinkedDriver::callsOn($this->changer->id));
    }

    public function test_the_same_input_reported_again_runs_nothing_twice(): void
    {
        $this->selects($this->lineIn());
        $this->selects($this->lineIn());
        // A track notification of the same source: no source of its own, the input stays.
        event(new NowPlayingUpdated((string) $this->player->id, new NowPlaying(state: 'playing'), 'media'));

        $this->assertSame([['powerOn', null], ['activateSource', 'CD']], FakeLinkedDriver::callsOn($this->changer->id));
    }

    public function test_standby_ends_the_link(): void
    {
        $this->selects($this->lineIn());
        FakeLinkedDriver::$log = [];

        DeviceCache::updateState($this->player->id, State::Standby);

        $this->assertSame([['standby', null]], FakeLinkedDriver::callsOn($this->changer->id));
    }

    public function test_an_ended_now_playing_ends_the_link(): void
    {
        $this->selects($this->lineIn());
        FakeLinkedDriver::$log = [];

        event(new NowPlayingEnded((string) $this->player->id));

        $this->assertSame([['standby', null]], FakeLinkedDriver::callsOn($this->changer->id));
    }

    public function test_an_unreachable_host_is_unknown_not_inactive(): void
    {
        $this->selects($this->lineIn());
        FakeLinkedDriver::$log = [];

        DeviceCache::updateState($this->player->id, State::Unreachable);

        $this->assertSame([], FakeLinkedDriver::callsOn($this->changer->id), 'A network drop must not switch the changer off.');

        // Back again on the same input: still active, so nothing to run.
        $this->selects($this->lineIn());
        $this->assertSame([], FakeLinkedDriver::callsOn($this->changer->id));
    }

    public function test_a_host_that_does_not_report_its_input_runs_nothing(): void
    {
        $unknown = Device::factory()->create(['device_name' => 'Philips', 'device_driver' => FakePlayerDriver::class]);
        $other = Device::factory()->create(['device_name' => 'Other', 'device_driver' => FakeLinkedDriver::class]);
        DeviceLinks::add($other, $unknown, 'source', 'LINE IN', ['on_activate' => [['action' => 'power_on']]]);

        event(new NowPlayingUpdated((string) $unknown->id, new NowPlaying(source: $this->lineIn()), 'media'));

        $this->assertSame([], FakeLinkedDriver::callsOn($other->id));
    }

    public function test_a_link_without_actions_runs_nothing_and_nothing_breaks(): void
    {
        $plain = Device::factory()->create(['device_name' => 'Plain', 'device_driver' => FakeLinkedDriver::class]);
        $this->link->delete();
        DeviceLinks::add($plain, $this->player, 'source', 'LINE IN');

        $this->selects($this->lineIn());

        $this->assertSame([], FakeLinkedDriver::callsOn($plain->id));
    }

    public function test_a_failing_action_does_not_stop_the_event_or_the_next_action(): void
    {
        $this->link->update(['meta' => ['on_activate' => [['action' => 'source', 'source_id' => 'CD'], ['action' => 'power_on']]]]);
        FakePlayerDriver::$throw = new \RuntimeException('ESP is off');
        Log::spy();

        $this->selects($this->lineIn());

        Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message) => str_contains($message, 'ESP is off'));

        // Both were tried (each threw: the fake throws before it records), the host's cache is up to date, and the
        // listener loop was not interrupted by the exception.
        $this->assertSame($this->lineIn()->jid, DeviceCache::getNowPlaying($this->player->id)->source->jid);
    }

    public function test_an_action_the_sub_device_cannot_do_is_skipped(): void
    {
        $bare = Device::factory()->create(['device_name' => 'Bare', 'device_driver' => \Tests\Support\FakeBareDriver::class]);
        $this->link->delete();
        DeviceLinks::add($bare, $this->player, 'source', 'LINE IN', ['on_activate' => [['action' => 'power_on']]]);

        $this->selects($this->lineIn());

        $this->assertSame([], FakeLinkedDriver::$log);
    }

    public function test_it_runs_for_any_host_event_whoever_changed_the_input(): void
    {
        // The change arrives as the host's own notification (a remote, the TV's menu): nothing of ours asked for it.
        $this->selects($this->lineIn());
        $this->assertSame(2, count(FakeLinkedDriver::callsOn($this->changer->id)));
    }
}
