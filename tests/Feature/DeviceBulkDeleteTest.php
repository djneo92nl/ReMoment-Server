<?php

namespace Tests\Feature;

use App\Livewire\DeviceDriverManager;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_devices_are_removed_and_others_kept(): void
    {
        $gone = Device::factory()->count(2)->create();
        $kept = Device::factory()->create();

        Livewire::test(DeviceDriverManager::class)
            ->set('selected', $gone->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('deleteSelected')
            ->assertSet('selected', []);

        $this->assertSame(0, Device::whereIn('id', $gone->pluck('id'))->count());
        $this->assertNotNull(Device::find($kept->id));
    }

    public function test_select_all_ticks_a_group_and_a_second_call_unticks_it(): void
    {
        $driverName = array_key_first(array_values(Livewire::test(DeviceDriverManager::class)->instance()->driverGroups())[0]['drivers']);
        Device::factory()->count(2)->create(['device_driver_name' => $driverName]);
        $component = Livewire::test(DeviceDriverManager::class);
        $groups = $component->instance()->driverGroups();
        $key = collect($groups)->filter(fn ($g) => $g['devices']->isNotEmpty())->keys()->first();
        $this->assertNotNull($key);
        $count = $groups[$key]['devices']->count();

        $component->call('toggleGroup', $key)->assertCount('selected', $count)
            ->call('toggleGroup', $key)->assertSet('selected', []);
    }
}
