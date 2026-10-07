<?php

namespace Tests\Feature;

use App\Domain\Device\Cache\MultiRoom;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\MultiRoomGroups;
use App\Domain\Device\MultiRoomStatus;
use App\Domain\Device\State;
use App\Livewire\DeviceCard;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** A device joined to a host that is on the dashboard is shown on the host's card, not as its own. */
class MultiRoomGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function makeDevice(string $name, string $jid): Device
    {
        $device = Device::factory()->create([
            'device_name' => $name,
        ]);
        $device->meta()->create(['key' => 'fake_id', 'value' => $jid]);

        return $device;
    }

    public function test_a_listener_with_its_host_in_the_list_is_left_out(): void
    {
        $m3 = $this->makeDevice('Beoplay M3', 'm3');
        $m5 = $this->makeDevice('Beoplayer M5', 'm5');
        $other = $this->makeDevice('Woonkamer', 'wk');
        MultiRoom::put($m3->id, MultiRoomStatus::hosting(['m5']));
        MultiRoom::put($m5->id, MultiRoomStatus::joined('m3'));

        $shown = MultiRoomGroups::withoutListeners(Device::all());

        $this->assertEqualsCanonicalizing([$m3->id, $other->id], $shown->pluck('id')->all());
    }

    public function test_a_listener_whose_host_is_not_listed_keeps_its_card(): void
    {
        $m3 = $this->makeDevice('Beoplay M3', 'm3');
        $m5 = $this->makeDevice('Beoplayer M5', 'm5');
        MultiRoom::put($m5->id, MultiRoomStatus::joined('m3'));

        $shown = MultiRoomGroups::withoutListeners(Device::where('id', $m5->id)->get());

        $this->assertSame([$m5->id], $shown->pluck('id')->all());
    }

    public function test_the_host_card_lists_its_members(): void
    {
        $m3 = $this->makeDevice('Beoplay M3', 'm3');
        $m5 = $this->makeDevice('Beoplayer M5', 'm5');
        MultiRoom::put($m3->id, MultiRoomStatus::hosting(['m5']));
        MultiRoom::put($m5->id, MultiRoomStatus::joined('m3'));

        $this->get('/devices')->assertOk()->assertSee('Multiroom · 2 rooms', false)->assertSee('Beoplayer M5');
    }

    public function test_the_multiroom_picker_lists_the_rooms_to_add(): void
    {
        $m3 = $this->makeDevice('Beoplay M3', 'm3');
        $m5 = $this->makeDevice('Beoplayer M5', 'm5');
        $this->makeDevice('Woonkamer', 'wk');
        DeviceCache::updateState($m3->id, State::Playing);
        cache()->put("listener_running_{$m3->id}", true, 60);
        FakePlayerDriver::$peers = ['m5', 'wk'];
        MultiRoom::put($m3->id, MultiRoomStatus::hosting(['m5']));

        Livewire::test(DeviceCard::class, ['device' => $m3->fresh()])
            ->call('loadMultiRoomData')
            ->assertSee('Beoplay M3')
            ->assertSee('Woonkamer');
    }

    public function test_a_room_volume_is_set_from_the_session_panel(): void
    {
        $m3 = $this->makeDevice('Beoplay M3', 'm3');
        $m5 = $this->makeDevice('Beoplayer M5', 'm5');

        Livewire::test(DeviceCard::class, ['device' => $m3])->call('setMemberVolume', $m5->id, 45);

        $this->assertSame(45, \App\Domain\Device\Cache\Volume::getVolume($m5->id));
    }
}
