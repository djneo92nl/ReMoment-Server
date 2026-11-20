<?php

namespace Tests\Feature;

use App\Domain\SubDevices\DeviceLinks;
use App\Livewire\ConnectedDevices;
use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\DeviceMeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeLinkedDriver;
use Tests\Support\FakeProductsDriver;
use Tests\TestCase;

/** The "Connected devices" card on the device edit page. */
class ConnectedDevicesCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeProductsDriver::reset();
    }

    private function device(string $name, string $driver = FakeLinkedDriver::class): Device
    {
        return Device::factory()->create(['device_name' => $name, 'device_driver' => $driver]);
    }

    public function test_the_edit_page_shows_the_card_to_the_admin_only(): void
    {
        $tv = $this->device('V1');

        $this->get("/devices/{$tv->id}/edit")->assertOk()->assertDontSee('Connected devices');

        $this->actingAs(User::factory()->create());
        $this->get("/devices/{$tv->id}/edit")->assertOk()->assertSee('Connected devices');
    }

    public function test_a_link_is_added_from_either_side_and_removed(): void
    {
        $this->actingAs(User::factory()->create());
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');

        Livewire::test(ConnectedDevices::class, ['device' => $tv])
            ->set('direction', 'sub')->set('otherId', $dongle->id)->set('kind', 'input')->set('via', 'hdmi_1')
            ->call('add')
            ->assertSet('error', null)
            ->assertSee('Chromecast');

        $link = DeviceLink::first();
        $this->assertSame([$dongle->id, $tv->id, 'hdmi_1'], [$link->device_id, $link->host_device_id, $link->via]);

        // The dongle's own card lists the host.
        Livewire::test(ConnectedDevices::class, ['device' => $dongle])->assertSee('V1')->call('remove', $link->id);
        $this->assertSame(0, DeviceLink::count());

        Livewire::test(ConnectedDevices::class, ['device' => $dongle])
            ->set('direction', 'host')->set('otherId', $tv->id)->set('kind', 'source')->set('via', 'hdmi_2')
            ->call('add')
            ->assertSet('error', null);
        $this->assertSame([$dongle->id, $tv->id, 'source'], [DeviceLink::first()->device_id, DeviceLink::first()->host_device_id, DeviceLink::first()->kind]);
    }

    public function test_a_refused_link_shows_the_reason_and_changes_nothing(): void
    {
        $this->actingAs(User::factory()->create());
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');

        Livewire::test(ConnectedDevices::class, ['device' => $dongle])
            ->set('direction', 'sub')->set('otherId', $tv->id)->set('kind', 'input')->set('via', 'hdmi_2')
            ->call('add')
            ->assertSee('sub devices go one level deep');

        $this->assertSame(1, DeviceLink::count());
    }

    public function test_a_guest_cannot_change_a_link(): void
    {
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        $link = DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');

        Livewire::test(ConnectedDevices::class, ['device' => $tv])->call('remove', $link->id)->assertForbidden();
        $this->assertSame(1, DeviceLink::count());
    }

    public function test_it_only_removes_links_of_its_own_device(): void
    {
        $this->actingAs(User::factory()->create());
        $tv = $this->device('V1');
        $dongle = $this->device('Chromecast');
        $unrelated = $this->device('Unrelated');
        $link = DeviceLinks::add($dongle, $tv, 'input', 'hdmi_1');

        Livewire::test(ConnectedDevices::class, ['device' => $unrelated])->call('remove', $link->id);

        $this->assertSame(1, DeviceLink::count());
    }

    public function test_the_links_a_player_reports_are_offered_and_accepted(): void
    {
        $this->actingAs(User::factory()->create());
        $player = $this->device('Moment', FakeProductsDriver::class);
        $converter = $this->device('Converter');
        DeviceMeta::create(['device_id' => $converter->id, 'key' => 'converter_jid', 'value' => '1790.1.2@products.bang-olufsen.com']);
        FakeProductsDriver::$products = ['products' => [['1790.1.2@products.bang-olufsen.com', ['borrowSource' => true]]]];

        Livewire::test(ConnectedDevices::class, ['device' => $player])
            ->call('findSuggestions')
            ->assertSee('Converter')
            ->call('accept', 0)
            ->assertSet('suggestions', [])
            ->assertSet('error', null);

        $link = DeviceLink::first();
        $this->assertSame([$converter->id, $player->id, 'borrow', ''], [$link->device_id, $link->host_device_id, $link->kind, $link->via]);
    }

    public function test_a_player_that_does_not_answer_says_so(): void
    {
        $this->actingAs(User::factory()->create());
        $player = $this->device('Moment', FakeProductsDriver::class);
        FakeProductsDriver::$productsThrow = new \RuntimeException('timeout');

        Livewire::test(ConnectedDevices::class, ['device' => $player])
            ->call('findSuggestions')
            ->assertSee('The device did not answer');
    }
}
