<?php

namespace Tests\Feature;

use App\Domain\Device\DiscoveredDevice;
use App\Domain\Device\DiscoveredDevicePersister;
use App\Domain\Device\DiscoveryInbox;
use App\Livewire\DeviceDriverManager;
use App\Models\Device;
use App\Models\DiscoveredDeviceRecord;
use App\Models\User;
use App\Services\NetworkDiscoveryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class DiscoveryInboxTest extends TestCase
{
    use RefreshDatabase;

    private function found(string $ip, array $meta = [], string $name = 'Kitchen', string $brand = 'Sonos'): DiscoveredDevice
    {
        return new DiscoveredDevice($ip, $name, $brand, 'Model', 'App\Fake\Driver', 'Fake', $meta);
    }

    private function fakeScan(array $found): void
    {
        $this->app->instance(NetworkDiscoveryService::class, new class($found) extends NetworkDiscoveryService
        {
            public function __construct(private array $found) {}

            public function discoverAll(?array $discovererClasses = null, array $hosts = []): array
            {
                return collect($this->found)->keyBy('ip_address')->all();
            }
        });
    }

    public function test_a_find_is_listed_not_added(): void
    {
        $counts = app(DiscoveryInbox::class)->record([$this->found('192.168.1.10')]);

        $this->assertSame(['new' => 1, 'known' => 0], $counts);
        $this->assertSame(0, Device::count());
        $this->assertSame(1, DiscoveredDeviceRecord::count());
    }

    public function test_a_known_device_is_refreshed_even_when_it_moved(): void
    {
        $device = app(DiscoveredDevicePersister::class)->persist($this->found('192.168.1.10', ['sonos_uuid' => 'A']));

        $counts = app(DiscoveryInbox::class)->record([$this->found('192.168.1.99', ['sonos_uuid' => 'A'])]);

        $this->assertSame(['new' => 0, 'known' => 1], $counts);
        $this->assertSame(0, DiscoveredDeviceRecord::count());
        $this->assertSame('192.168.1.99', $device->fresh()->ip_address);
        $this->assertSame(1, Device::count());
    }

    public function test_a_rescan_keeps_ignored_and_the_edited_name(): void
    {
        $inbox = app(DiscoveryInbox::class);
        $inbox->record([$this->found('192.168.1.10')]);
        $record = DiscoveredDeviceRecord::first();
        $inbox->ignore($record);
        $record->update(['device_name' => 'Mine']);

        $inbox->record([$this->found('192.168.1.10', [], 'Kitchen')]);

        $this->assertSame(1, DiscoveredDeviceRecord::count());
        $this->assertNotNull($record->fresh()->ignored_at);
        $this->assertSame('Mine', $record->fresh()->device_name);
    }

    public function test_adding_creates_the_device_with_meta_and_clears_the_row(): void
    {
        $inbox = app(DiscoveryInbox::class);
        $inbox->record([$this->found('192.168.1.10', ['sonos_uuid' => 'A'])]);

        $device = $inbox->add(DiscoveredDeviceRecord::first(), 'Living Room');

        $this->assertSame('Living Room', $device->device_name);
        $this->assertSame('A', $device->meta()->where('key', 'sonos_uuid')->value('value'));
        $this->assertSame(0, DiscoveredDeviceRecord::count());
    }

    public function test_the_page_scans_into_the_list_and_adds_from_it(): void
    {
        $this->fakeScan([$this->found('192.168.1.10'), $this->found('192.168.1.11', [], 'Bedroom')]);

        $page = Livewire::actingAs(User::factory()->create())->test(DeviceDriverManager::class)->call('scan');

        $this->assertSame(2, DiscoveredDeviceRecord::count());
        $this->assertSame(0, Device::count());

        $id = DiscoveredDeviceRecord::where('ip_address', '192.168.1.10')->value('id');
        $page->set("names.$id", 'Hall')->call('addDiscovered', $id);
        $this->assertSame(['Hall'], Device::pluck('device_name')->all());

        $other = DiscoveredDeviceRecord::first();
        $page->call('ignore', $other->id)->call('addAll');
        $this->assertSame(1, Device::count());

        $page->call('restore', $other->id)->call('addAll');
        $this->assertSame(2, Device::count());
        $this->assertSame(0, DiscoveredDeviceRecord::count());
    }

    public function test_the_command_lists_by_default_and_adds_with_the_flag(): void
    {
        $this->fakeScan([$this->found('192.168.1.10')]);

        Artisan::call('device:discover');
        $this->assertSame(0, Device::count());
        $this->assertSame(1, DiscoveredDeviceRecord::count());

        Artisan::call('device:discover', ['--add' => true]);
        $this->assertSame(1, Device::count());
    }

    public function test_the_scan_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'device:discover'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
