<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Domain\Media\Source;
use App\Domain\SubDevices\ActiveInput;
use App\Domain\SubDevices\DeviceLinks;
use App\Domain\SubDevices\FollowRule;
use App\Events\Device\NowPlayingUpdated;
use App\Jobs\FollowStandby;
use App\Jobs\FollowSubDevice;
use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeLinkedDriver;
use Tests\TestCase;

/**
 * Workstream 2a, step 3: the follow rule. A Chromecast on the V1's HDMI 1 starts casting and the V1 selects `hdmi_1`, which
 * wakes it. The queue is `sync` in tests (a dispatched job has run when the event returns); `Queue::fake()` is used where
 * the delay itself is the point.
 */
class SubDeviceFollowTest extends TestCase
{
    use RefreshDatabase;

    private Device $tv;

    private Device $dongle;

    protected function setUp(): void
    {
        parent::setUp();

        FakeLinkedDriver::reset();
        $this->tv = Device::factory()->create(['device_name' => 'V1', 'device_driver' => FakeLinkedDriver::class]);
        $this->dongle = Device::factory()->create(['device_name' => 'Chromecast', 'device_driver' => FakeLinkedDriver::class]);
        DeviceCache::updateState($this->tv->id, State::Standby);
        DeviceCache::updateState($this->dongle->id, State::Standby);
    }

    private function link(array $meta = []): DeviceLink
    {
        return DeviceLinks::add($this->dongle, $this->tv, 'input', 'hdmi_1', $meta);
    }

    private function hdmi(string $n): Source
    {
        return new Source(name: 'HDMI '.$n, category: 'video', jid: "hdmi_{$n}", sourceType: 'HDMI', connector: $n);
    }

    private function hostOn(string $n): void
    {
        DeviceCache::updateState($this->tv->id, State::Playing);
        ActiveInput::put($this->tv->id, $this->hdmi($n));
    }

    private function castStarts(): void
    {
        DeviceCache::updateState($this->dongle->id, State::Playing);
    }

    public function test_a_cast_wakes_a_host_in_standby_and_switches_it_to_the_input(): void
    {
        $link = $this->link();

        $this->castStarts();

        $this->assertSame([['activateSource', 'hdmi_1']], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertTrue($link->fresh()->meta['state']['woke_host'], 'The rule woke it: the mark that allows turning it off.');
    }

    public function test_by_default_a_host_on_another_input_is_left_alone(): void
    {
        $this->link();
        $this->hostOn('2');

        $this->castStarts();

        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id), 'A cast does not interrupt the set-top box.');
    }

    public function test_always_takes_the_screen_from_another_input_without_marking_the_host_as_woken(): void
    {
        $link = $this->link(['follow' => 'always']);
        $this->hostOn('2');

        $this->castStarts();

        $this->assertSame([['activateSource', 'hdmi_1']], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertArrayNotHasKey('state', $link->fresh()->meta, 'The TV was on: it is nobody to turn off.');
    }

    public function test_off_switches_nothing_in_any_state(): void
    {
        $this->link(['follow' => 'off']);

        $this->castStarts();

        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id));
    }

    public function test_a_host_already_on_the_input_is_not_switched_or_marked(): void
    {
        $link = $this->link();
        $this->hostOn('1');
        DeviceCache::updateState($this->dongle->id, State::Playing);

        $this->assertSame('already_on_input', FollowRule::follow($this->prepared($link)));
        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertNull($link->fresh()->meta);
    }

    public function test_an_unreachable_host_is_left_alone(): void
    {
        $link = $this->link();
        DeviceCache::updateState($this->tv->id, State::Unreachable);

        $this->castStarts();

        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertSame('host_unreachable', FollowRule::follow($this->prepared($link)));
    }

    public function test_it_checks_again_after_the_debounce_that_the_session_still_plays(): void
    {
        $link = $this->link();
        // The app started and stopped within the debounce window: by the time the job runs the dongle is idle.
        DeviceCache::updateState($this->dongle->id, State::Standby);

        $this->assertSame('sub_idle', FollowRule::follow($this->prepared($link)));
        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id));
    }

    public function test_the_check_is_delayed_and_a_burst_of_starts_makes_one(): void
    {
        Queue::fake();
        config(['sub-devices.follow_debounce_seconds' => 7]);
        $link = $this->link();

        $this->castStarts();
        DeviceCache::updateState($this->dongle->id, State::Standby);
        $this->castStarts();
        DeviceCache::updateState($this->dongle->id, State::Standby);
        $this->castStarts();

        Queue::assertPushed(FollowSubDevice::class, 1);
        Queue::assertPushed(FollowSubDevice::class, fn (FollowSubDevice $job) => $job->linkId === $link->id && $job->delay !== null && now()->diffInSeconds($job->delay, false) > 5);
    }

    public function test_pausing_and_resuming_is_not_a_new_session(): void
    {
        Queue::fake();
        $this->link();
        $this->castStarts();
        Cache::flush();   // the debounce window is over

        DeviceCache::updateState($this->dongle->id, State::Paused);
        DeviceCache::updateState($this->dongle->id, State::Playing);

        Queue::assertPushed(FollowSubDevice::class, 1);
    }

    public function test_only_input_and_source_links_follow(): void
    {
        Queue::fake();
        $converter = Device::factory()->create(['device_driver' => FakeLinkedDriver::class]);
        DeviceMeta::create(['device_id' => $converter->id, 'key' => 'converter_jid', 'value' => '1790.1.2@x']);
        DeviceLinks::add($converter, $this->tv, 'borrow');
        DeviceCache::updateState($converter->id, State::Standby);

        DeviceCache::updateState($converter->id, State::Playing);

        Queue::assertNothingPushed();
    }

    public function test_a_cast_that_ends_schedules_the_hosts_standby_only_when_the_rule_woke_it(): void
    {
        Queue::fake();
        $link = $this->link(['follow_off_after' => 15]);

        // A TV someone turned on: no mark, no standby job.
        $this->castStarts();
        DeviceCache::updateState($this->dongle->id, State::Standby);
        Queue::assertNotPushed(FollowStandby::class);

        // The rule woke it.
        $link->update(['meta' => [...$link->fresh()->meta, 'state' => ['woke_host' => true]]]);
        $this->castStarts();
        DeviceCache::updateState($this->dongle->id, State::Standby);

        Queue::assertPushed(FollowStandby::class, 1);
        Queue::assertPushed(FollowStandby::class, fn (FollowStandby $job) => $job->linkId === $link->id && now()->diffInMinutes($job->delay, false) >= 14);
    }

    public function test_without_follow_off_after_the_host_stays_on(): void
    {
        Queue::fake();
        $link = $this->link();
        $link->update(['meta' => ['state' => ['woke_host' => true]]]);

        $this->castStarts();
        DeviceCache::updateState($this->dongle->id, State::Standby);

        Queue::assertNotPushed(FollowStandby::class);
    }

    public function test_the_hosts_standby_runs_when_the_rule_woke_it_and_it_is_still_on_the_input(): void
    {
        $link = $this->link(['follow_off_after' => 15]);
        $this->castStarts();
        $this->assertTrue($link->fresh()->meta['state']['woke_host']);
        $this->hostOn('1');
        FakeLinkedDriver::$log = [];

        // The cast ends. The sync queue of the tests ignores the delay (the next test checks it), so the job runs now.
        DeviceCache::updateState($this->dongle->id, State::Standby);

        $this->assertSame([['standby', null]], FakeLinkedDriver::callsOn($this->tv->id));
        $this->assertArrayNotHasKey('state', $link->fresh()->meta);
    }

    public function test_the_hosts_standby_is_skipped_when_the_cast_came_back_the_host_moved_or_a_later_stop_exists(): void
    {
        Queue::fake();
        $link = $this->link(['follow_off_after' => 15]);
        $link->update(['meta' => [...$link->fresh()->meta, 'state' => ['woke_host' => true]]]);
        Cache::put("device_link:{$link->id}:stopped_at", 1000);
        $this->hostOn('1');

        // A later stop supersedes this job.
        $this->assertSame('superseded', FollowRule::standbyAfterStop($this->prepared($link), 999));

        // The cast is back.
        DeviceCache::updateState($this->dongle->id, State::Playing);
        $this->assertSame('sub_active', FollowRule::standbyAfterStop($this->prepared($link), 1000));

        // Someone moved the TV to another input: it is theirs now, and the mark goes.
        DeviceCache::updateState($this->dongle->id, State::Standby);   // stops again: this stop is the one that counts now
        $stoppedAt = Cache::get("device_link:{$link->id}:stopped_at");
        $this->hostOn('2');
        $this->assertNotSame(1000, $stoppedAt);
        $this->assertSame('host_moved', FollowRule::standbyAfterStop($this->prepared($link), $stoppedAt));
        $this->assertArrayNotHasKey('state', $link->fresh()->meta);
        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id));

        // And with the mark gone, nothing is turned off.
        $this->assertSame('not_woken_by_rule', FollowRule::standbyAfterStop($this->prepared($link), $stoppedAt));
    }

    public function test_the_mark_is_cleared_as_soon_as_the_link_ends_whoever_ended_it(): void
    {
        $link = $this->link(['follow_off_after' => 15]);
        $this->castStarts();
        // The V1 reports the input the rule selected: the link becomes active, the mark stays.
        event(new NowPlayingUpdated((string) $this->tv->id, new \App\Domain\Media\NowPlaying(type: 'video', platform: 'media', source: $this->hdmi('1')), 'media'));
        $this->assertTrue($link->fresh()->meta['state']['woke_host']);

        // Someone picks another input with the remote.
        event(new NowPlayingUpdated((string) $this->tv->id, new \App\Domain\Media\NowPlaying(type: 'video', platform: 'media', source: $this->hdmi('3')), 'media'));

        $this->assertArrayNotHasKey('state', $link->fresh()->meta);
    }

    public function test_the_wake_the_rule_causes_does_not_meet_the_default_input_on_wake(): void
    {
        DeviceMeta::create(['device_id' => $this->tv->id, 'key' => 'default_source', 'value' => 'MUSIC']);
        $this->link();

        // The rule switches the TV to hdmi_1; the TV then wakes (standby to playing), which would select its default input.
        $this->castStarts();
        FakeLinkedDriver::$log = [];
        DeviceCache::updateState($this->tv->id, State::Playing);

        $this->assertSame([], FakeLinkedDriver::callsOn($this->tv->id), 'The default input did not take the screen back.');

        // A wake nobody asked for still goes to the default input.
        DeviceCache::updateState($this->tv->id, State::Standby);
        Cache::forget("device:{$this->tv->id}:follow_switched");
        DeviceCache::updateState($this->tv->id, State::Playing);

        $this->assertSame([['activateSource', 'MUSIC']], FakeLinkedDriver::callsOn($this->tv->id));
    }

    public function test_a_host_that_cannot_switch_by_source_is_left_alone(): void
    {
        $bare = Device::factory()->create(['device_driver' => \Tests\Support\FakePowerDriver::class]);
        $link = DeviceLinks::add($this->dongle, $bare, 'input', 'hdmi_1');
        DeviceCache::updateState($bare->id, State::Standby);
        DeviceCache::updateState($this->dongle->id, State::Playing);

        $this->assertSame('cannot_switch', FollowRule::follow($link->fresh()));
    }

    /** The link as the job loads it: fresh, with its devices. */
    private function prepared(DeviceLink $link): DeviceLink
    {
        return DeviceLink::with('device', 'host')->findOrFail($link->id);
    }
}
