<?php

namespace Tests\Feature;

use App\Domain\Device\ListenerStatus;
use App\Livewire\SystemHealth;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ListenerStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_ip_forgets_upnp_urls_and_records_the_change(): void
    {
        $device = Device::factory()->create(['ip_address' => '192.168.1.118']);
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'upnp_avtransport_url', 'value' => 'http://192.168.1.118:49152/ctl']);
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'philips_user', 'value' => 'keep']);

        $device->update(['ip_address' => '192.168.1.19']);

        $this->assertSame(['philips_user'], $device->meta()->pluck('key')->all());
        $change = ListenerStatus::ipChanges()[0];
        $this->assertSame(['192.168.1.118', '192.168.1.19'], [$change['from'], $change['to']]);
        $this->assertSame([$device->id], ListenerStatus::pullRestartRequests([$device->id]));
    }

    public function test_saving_without_an_ip_change_records_nothing(): void
    {
        $device = Device::factory()->create();
        $device->update(['device_name' => 'Other']);

        $this->assertSame([], ListenerStatus::ipChanges());
    }

    public function test_health_page_shows_why_and_since_and_restart_needs_login(): void
    {
        $device = Device::factory()->create();
        ListenerStatus::transition($device->id, ListenerStatus::RETRYING, 'The listener process ended (1) after 2s.', ['failures' => 3, 'retry_at' => time() + 20]);
        ListenerStatus::error($device->id, 'Connection refused');

        $page = Livewire::test(SystemHealth::class)
            ->assertSee('Retrying since')
            ->assertSee('The listener process ended (1) after 2s.')
            ->assertSee('Connection refused');

        $page->call('restartListener', $device->id)->assertForbidden();

        $this->actingAs(User::factory()->create());
        Livewire::test(SystemHealth::class)->call('restartListener', $device->id);

        $this->assertSame([$device->id], ListenerStatus::pullRestartRequests([$device->id]));
        $this->assertSame([], ListenerStatus::pullRestartRequests([$device->id]));
    }
}
