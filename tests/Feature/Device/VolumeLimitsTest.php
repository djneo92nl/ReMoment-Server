<?php

namespace Tests\Feature\Device;

use App\Domain\Device\Cache\Volume;
use App\Domain\Device\DeviceVolume;
use App\Domain\Device\State;
use App\Domain\Device\VolumeLimits;
use App\Events\Device\VolumeUpdated;
use App\Livewire\DeviceVolumeLimits;
use App\Models\AdminToken;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

class VolumeLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_no_limit_by_default(): void
    {
        $device = Device::factory()->create();

        $this->assertNull(VolumeLimits::capFor($device));
        $this->assertSame(90, VolumeLimits::clamp($device, 90));
    }

    public function test_the_quiet_limit_applies_inside_quiet_hours_also_past_midnight(): void
    {
        $device = Device::factory()->create();
        VolumeLimits::set($device, ['max' => 60, 'quiet_max' => 25, 'quiet_from' => '22:00', 'quiet_until' => '07:00']);

        $this->assertSame(60, VolumeLimits::capFor($device, Carbon::parse('2026-11-13 21:59')));
        $this->assertSame(25, VolumeLimits::capFor($device, Carbon::parse('2026-11-13 22:00')));
        $this->assertSame(25, VolumeLimits::capFor($device, Carbon::parse('2026-11-14 06:59')));
        $this->assertSame(60, VolumeLimits::capFor($device, Carbon::parse('2026-11-14 07:00')));
    }

    public function test_a_quiet_limit_above_the_maximum_never_raises_it(): void
    {
        $device = Device::factory()->create();
        VolumeLimits::set($device, ['max' => 20, 'quiet_max' => 40, 'quiet_from' => '00:00', 'quiet_until' => '23:59']);

        $this->assertSame(20, VolumeLimits::capFor($device, Carbon::parse('2026-11-13 12:00')));
    }

    public function test_the_api_sets_at_most_the_limit_and_answers_what_was_set(): void
    {
        $device = Device::factory()->inState(State::Playing)->create();
        VolumeLimits::set($device, ['max' => 50]);

        $this->putJson("/api/devices/{$device->id}/volume", ['volume' => 80])->assertOk()->assertExactJson(['volume' => 50]);
        $this->assertContains(['setVolume', 50], FakePlayerDriver::$calls);
        $this->assertSame(50, Volume::getVolume($device->id));

        $this->putJson("/api/devices/{$device->id}/volume", ['volume' => 30])->assertExactJson(['volume' => 30]);
    }

    public function test_the_web_volume_setter_is_limited_too(): void
    {
        $device = Device::factory()->create();
        VolumeLimits::set($device, ['max' => 40]);

        $this->assertSame(40, DeviceVolume::set($device, 95));
    }

    public function test_a_device_that_reports_a_volume_above_the_limit_is_set_back_once(): void
    {
        $device = Device::factory()->create();
        VolumeLimits::set($device, ['max' => 40]);

        VolumeUpdated::dispatch((string) $device->id, 70);
        VolumeUpdated::dispatch((string) $device->id, 72);

        $this->assertSame([['setVolume', 40]], array_values(array_filter(FakePlayerDriver::$calls, fn ($call) => $call[0] === 'setVolume')));
    }

    public function test_a_report_within_the_limit_changes_nothing(): void
    {
        $device = Device::factory()->create();
        VolumeLimits::set($device, ['max' => 40]);

        VolumeUpdated::dispatch((string) $device->id, 40);

        $this->assertSame([], FakePlayerDriver::$calls);
        $this->assertSame(40, Volume::getVolume($device->id));
    }

    public function test_a_device_that_is_too_loud_when_quiet_hours_begin_is_lowered(): void
    {
        Carbon::setTestNow('2026-11-13 21:59');
        $device = Device::factory()->create();
        $other = Device::factory()->create();
        VolumeLimits::set($device, ['quiet_max' => 20, 'quiet_from' => '22:00', 'quiet_until' => '07:00']);
        Volume::updateVolume($device->id, 55);
        Volume::updateVolume($other->id, 90);

        $this->assertSame(0, VolumeLimits::enforceDue());

        Carbon::setTestNow('2026-11-13 22:00');
        $this->assertSame(1, VolumeLimits::enforceDue());
        $this->assertSame(20, Volume::getVolume($device->id));
        $this->assertSame(90, Volume::getVolume($other->id));
    }

    public function test_reading_is_open_and_changing_needs_the_admin_token(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/volume-limits")->assertOk()
            ->assertExactJson(['max' => null, 'quiet_max' => null, 'quiet_from' => null, 'quiet_until' => null, 'limit' => null]);
        $this->putJson("/api/admin/devices/{$device->id}/volume-limits", ['max' => 50])->assertUnauthorized();

        User::factory()->create();
        [, $token] = AdminToken::issue('test');
        $auth = ['Authorization' => 'Bearer '.$token];
        $this->putJson("/api/admin/devices/{$device->id}/volume-limits", ['max' => 50], $auth)
            ->assertOk()->assertJson(['max' => 50, 'limit' => 50]);
        $this->getJson("/api/devices/{$device->id}")->assertJsonPath('data.volume_limit', 50);

        $this->putJson("/api/admin/devices/{$device->id}/volume-limits", ['max' => null], $auth)->assertJson(['limit' => null]);
        $this->assertDatabaseMissing('device_meta', ['device_id' => $device->id, 'key' => VolumeLimits::META_KEY]);
    }

    public function test_the_card_saves_for_the_admin_only(): void
    {
        $device = Device::factory()->create();

        Livewire::test(DeviceVolumeLimits::class, ['device' => $device])->set('max', 45)->call('save')->assertForbidden();
        $this->assertNull(VolumeLimits::capFor($device));

        Livewire::actingAs(User::factory()->create())->test(DeviceVolumeLimits::class, ['device' => $device])
            ->set('max', 45)->call('save')->assertHasNoErrors();
        $this->assertSame(45, VolumeLimits::capFor($device));
    }
}
