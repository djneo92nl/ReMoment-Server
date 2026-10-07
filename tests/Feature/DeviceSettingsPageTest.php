<?php

namespace Tests\Feature;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\State;
use App\Livewire\DeviceSettings;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeBareDriver;
use Tests\Support\FakeNetworkDriver;
use Tests\Support\FakeSettingsDriver;
use Tests\Support\FakeSleepDigitsDriver;
use Tests\Support\FakeSourceDriver;
use Tests\Support\FakeWiredSpeakersDriver;
use Tests\TestCase;

class DeviceSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSettingsDriver::reset();
    }

    private function makeDevice(string $driver = FakeSettingsDriver::class, State $state = State::Standby): Device
    {
        $device = Device::create([
            'ip_address' => '10.0.0.10',
            'device_name' => 'Living Room',
            'device_brand_name' => 'Test',
            'device_product_type' => 'Speaker',
            'device_driver' => $driver,
            'device_driver_name' => 'Fake',
        ]);

        DeviceCache::updateState($device->id, $state);

        return $device;
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->create());
    }

    public function test_the_settings_page_is_admin_only(): void
    {
        $device = $this->makeDevice();

        $this->get("/devices/{$device->id}/settings")->assertRedirect('/login');
        $this->admin()->get("/devices/{$device->id}/settings")->assertOk()->assertSeeLivewire(DeviceSettings::class);
    }

    public function test_the_device_page_links_to_settings_for_admins_only(): void
    {
        $device = $this->makeDevice();

        $this->get("/devices/{$device->id}")->assertOk()->assertDontSee("/devices/{$device->id}/settings");
        $this->admin()->get("/devices/{$device->id}")->assertOk()->assertSee("/devices/{$device->id}/settings");
    }

    public function test_a_device_without_settings_gets_no_link(): void
    {
        $device = $this->makeDevice(FakeBareDriver::class);

        $this->admin()->get("/devices/{$device->id}")->assertOk()->assertDontSee("/devices/{$device->id}/settings");
        Livewire::test(DeviceSettings::class, ['device' => $device])->call('load')->assertSee('no settings ReMoment can change');
    }

    public function test_shows_every_section_the_device_supports(): void
    {
        $device = $this->makeDevice();

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->assertSee('Bass')
            ->assertSee('Loudness')
            ->assertSee('Discoverable')
            ->assertSee('Remko iPhone')
            ->assertSee('FAKE_SPEAKER')
            ->assertSet('problems', []);
    }

    public function test_saves_and_clears_the_default_input(): void
    {
        FakeSourceDriver::$activated = [];
        $device = $this->makeDevice(FakeSourceDriver::class);
        $this->admin();

        $page = Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->assertSee('switch to')
            ->assertSee('shared from Beolab 28')
            ->call('setDefaultSource', 'tv')
            ->assertSet('defaultSource', 'tv');
        $this->assertSame('tv', $device->meta()->where('key', 'default_source')->value('value'));

        $page->call('setDefaultSource', 'bogus');
        $this->assertSame('tv', $device->meta()->where('key', 'default_source')->value('value'));

        $page->call('setDefaultSource', '');
        $this->assertNull($device->meta()->where('key', 'default_source')->value('value'));

        $page->call('switchInput', 'tv');
        $this->assertSame(['tv'], FakeSourceDriver::$activated);
    }

    public function test_the_rename_form_is_hidden_when_the_platform_cannot_rename(): void
    {
        $device = $this->makeDevice();
        $this->admin();

        Livewire::test(DeviceSettings::class, ['device' => $device])->call('load')->assertSee('Rename');

        FakeSettingsDriver::$renamable = false;
        Livewire::test(DeviceSettings::class, ['device' => $device])->call('load')->assertDontSee('Rename')->assertSee('Living Room');
    }

    public function test_changes_sound_and_shows_what_the_device_reports(): void
    {
        $device = $this->makeDevice();

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->call('setSound', 'bass', 4)
            ->call('setLoudness', false)
            ->assertSet('sound.bass.value', 4)
            ->assertSet('sound.loudness', false);

        $this->assertSame([['setSoundAdjustment', 4, null, null], ['setSoundAdjustment', null, null, false]], FakeSettingsDriver::$calls);
    }

    public function test_makes_the_device_discoverable_and_forgets_a_phone(): void
    {
        $device = $this->makeDevice();

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->call('setDiscoverable', true)
            ->assertSet('bluetooth.discoverable', true)
            ->assertSet('notices.bluetooth', 'Discoverable. Pair from the phone now.')
            ->call('removeBluetoothDevice', 'phone-1')
            ->assertSet('bluetooth.devices', []);
    }

    public function test_renames_the_device_and_keeps_our_copy_in_step(): void
    {
        $device = $this->makeDevice();

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->set('name', 'Kitchen')
            ->call('rename')
            ->assertSet('info.name', 'Kitchen');

        $this->assertSame('Kitchen', $device->fresh()->device_name);
    }

    public function test_a_blank_name_is_refused_without_calling_the_device(): void
    {
        $device = $this->makeDevice();

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->set('name', '   ')
            ->call('rename')
            ->assertSet('problems.device_info', 'The name must be 1 to 64 characters.');

        $this->assertSame([], FakeSettingsDriver::$calls);
    }

    public function test_a_failing_section_shows_its_error_and_the_others_keep_working(): void
    {
        $device = $this->makeDevice();

        $this->admin();
        $component = Livewire::test(DeviceSettings::class, ['device' => $device])->call('load');

        FakeSettingsDriver::$writable = false;
        $component->call('setDiscoverable', true)
            ->assertSet('problems.bluetooth', 'This device only lists paired Bluetooth devices.')
            ->assertSet('bluetooth.writable', false)
            ->call('setSound', 'bass', 2)
            ->assertSet('sound.bass.value', 2)
            ->assertSet('problems.sound_adjustment', null);
    }

    public function test_an_unreachable_device_says_so_instead_of_showing_stale_values(): void
    {
        $device = $this->makeDevice(state: State::Unreachable);

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->assertSet('problems.bluetooth', 'The device is not reachable.')
            ->assertSet('bluetooth', null);
    }

    public function test_sets_the_sleep_timer_and_shows_what_the_device_reports(): void
    {
        FakeSleepDigitsDriver::reset();
        $device = $this->makeDevice(FakeSleepDigitsDriver::class);

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->assertSee('Sleep timer')
            ->call('setSleepTimer', 30)
            ->assertSet('sleepTimer.value', 30)
            ->assertSee('30 min left');

        $this->assertSame(30, FakeSleepDigitsDriver::$minutes);
    }

    public function test_a_read_only_sleep_timer_is_not_offered_for_change(): void
    {
        FakeSleepDigitsDriver::reset();
        FakeSleepDigitsDriver::$writable = false;
        $device = $this->makeDevice(FakeSleepDigitsDriver::class);

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->assertSee('set it remotely')
            ->call('setSleepTimer', 10)
            ->assertSet('problems.sleep_timer', 'This device cannot set a sleep timer.');
    }

    public function test_the_number_keys_are_sent_to_the_device(): void
    {
        FakeSleepDigitsDriver::reset();
        $device = $this->makeDevice(FakeSleepDigitsDriver::class);

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->assertSee('Number keys')
            ->call('pressDigit', 4)
            ->call('pressDigit', 0)
            ->assertSet('problems', []);

        $this->assertSame([4, 0], FakeSleepDigitsDriver::$digits);
    }

    public function test_guests_cannot_press_number_keys(): void
    {
        FakeSleepDigitsDriver::reset();
        $device = $this->makeDevice(FakeSleepDigitsDriver::class);

        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('pressDigit', 1)
            ->assertStatus(403);

        $this->assertSame([], FakeSleepDigitsDriver::$digits);
    }

    public function test_guests_cannot_run_actions(): void
    {
        $device = $this->makeDevice();

        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->call('setDiscoverable', true)
            ->assertStatus(403);

        $this->assertSame([], FakeSettingsDriver::$calls);
    }

    private function networkDevice(string $product): Device
    {
        FakeNetworkDriver::reset();

        $device = Device::create([
            'ip_address' => '10.0.0.11',
            'device_name' => 'Speaker',
            'device_brand_name' => 'Bang & Olufsen',
            'device_product_type' => $product,
            'device_driver' => FakeNetworkDriver::class,
            'device_driver_name' => 'Fake',
        ]);
        DeviceCache::updateState($device->id, State::Standby);

        return $device;
    }

    public function test_shows_the_network_and_applies_a_change_without_re_reading(): void
    {
        $device = $this->networkDevice('BeoSound Essence');

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->assertSee('192.168.1.25')
            ->assertSee('HomeNet')
            ->assertDontSee('Wireless speakers')
            ->call('setInterface', 'wireless')
            ->assertSet('notices.network_settings', fn ($notice) => str_contains($notice, 'Applied'))
            ->assertSet('problems', []);

        $this->assertSame([['setActiveInterface', 'wireless']], FakeNetworkDriver::$calls);
    }

    public function test_the_static_address_form_sends_what_was_typed(): void
    {
        $device = $this->networkDevice('BeoSound Essence');

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->set('wiredDhcp', false)
            ->set('wired.address', '192.168.1.50')
            ->set('wired.subnet_mask', '255.255.255.0')
            ->set('wired.gateway', '192.168.1.1')
            ->call('saveWired')
            ->assertSet('problems', []);

        $this->assertSame('setWiredAddress', FakeNetworkDriver::$calls[0][0]);
        $this->assertFalse(FakeNetworkDriver::$calls[0][1]);
        $this->assertSame('192.168.1.50', FakeNetworkDriver::$calls[0][2]['address']);
    }

    public function test_joining_wifi_clears_the_passphrase_from_the_page(): void
    {
        $device = $this->networkDevice('BeoSound Essence');

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->set('wifiSsid', 'Guest')
            ->set('wifiPassphrase', 'correct horse')
            ->call('joinWifi')
            ->assertSet('wifiPassphrase', '');

        $this->assertSame([['joinWifi', 'Guest', 'correct horse', null]], FakeNetworkDriver::$calls);
    }

    public function test_a_refused_network_change_shows_the_reason(): void
    {
        $device = $this->networkDevice('BeoSound Essence');

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->set('wiredDhcp', false)
            ->set('wired.address', '')
            ->call('saveWired')
            ->assertSet('problems.network_settings', 'A static address needs address.');
    }

    public function test_only_the_moment_gets_the_wireless_speakers_card(): void
    {
        $this->admin();

        Livewire::test(DeviceSettings::class, ['device' => $this->networkDevice('Beoplay M3')])->call('load')->assertDontSee('Wireless speakers');

        Livewire::test(DeviceSettings::class, ['device' => $this->networkDevice('BeoSound Moment')])
            ->call('load')
            ->assertSee('Wireless speakers')
            ->assertSee('Not scanning')
            ->call('startScan')
            ->assertSee('Scanning for speakers')
            ->assertSee('Front Left')
            ->assertSet('wirelessSpeakers.scanning', true)
            ->call('stopScan')
            ->assertSet('wirelessSpeakers.scanning', false);
    }

    public function test_guests_cannot_change_the_network_or_reload(): void
    {
        $device = $this->networkDevice('BeoSound Essence');

        Livewire::test(DeviceSettings::class, ['device' => $device])->call('load')->call('setInterface', 'wireless')->assertStatus(403);
        Livewire::test(DeviceSettings::class, ['device' => $device])->call('load')->call('reload', 'network_settings')->assertStatus(403);

        $this->assertSame([], FakeNetworkDriver::$calls);
    }

    private function wiredDevice(string $product): Device
    {
        FakeWiredSpeakersDriver::reset();

        $device = Device::create([
            'ip_address' => '10.0.0.12',
            'device_name' => 'Speaker',
            'device_brand_name' => 'Bang & Olufsen',
            'device_product_type' => $product,
            'device_driver' => FakeWiredSpeakersDriver::class,
            'device_driver_name' => 'Fake',
        ]);
        DeviceCache::updateState($device->id, State::Standby);

        return $device;
    }

    public function test_the_speakers_card_shows_for_external_speaker_models_only(): void
    {
        $this->admin();

        Livewire::test(DeviceSettings::class, ['device' => $this->wiredDevice('Beoplay M3')])->call('load')->assertDontSee('Left output');

        Livewire::test(DeviceSettings::class, ['device' => $this->wiredDevice('BeoSound Essence')])
            ->call('load')
            ->assertSee('Left output')
            ->assertSee('Right output')
            ->assertSee('Speaker detected')
            ->assertSee('Beolab 3');
    }

    public function test_changes_a_speaker_type_and_shows_what_the_device_reports(): void
    {
        $device = $this->wiredDevice('BeoSound Moment');

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->call('setWiredSpeakerType', 'pl_1', 'Beolab 3')
            ->assertSet('wiredSpeakers.speakers.0.type', 'Beolab 3')
            ->assertSet('notices.wired_speakers', 'Saved.');

        $this->assertSame([['pl_1', 'Beolab 3', null]], FakeWiredSpeakersDriver::$calls);
    }

    public function test_a_refused_speaker_type_shows_the_reason(): void
    {
        $device = $this->wiredDevice('BeoSound Moment');

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->call('setWiredSpeakerType', 'pl_1', 'Beolab 99')
            ->assertSet('problems.wired_speakers', "Unknown speaker type 'Beolab 99'.");
    }

    public function test_a_test_noise_left_on_can_be_stopped_but_not_started_here(): void
    {
        $device = $this->wiredDevice('BeoSound Moment');
        FakeWiredSpeakersDriver::$outputs['pl_2']['sound'] = 'localizationNoise';

        $this->admin();
        Livewire::test(DeviceSettings::class, ['device' => $device])
            ->call('load')
            ->assertSee('A test noise is playing on the right output')
            ->call('stopWiredSpeakerNoise', 'pl_2')
            ->assertDontSee('A test noise is playing')
            ->assertSet('wiredSpeakers.speakers.1.sound', 'none');

        $this->assertStringNotContainsString('localizationNoise', file_get_contents(resource_path('views/livewire/device-settings.blade.php')), 'the page never offers to start the noise');
    }

    public function test_guests_cannot_change_a_speaker(): void
    {
        $device = $this->wiredDevice('BeoSound Moment');

        Livewire::test(DeviceSettings::class, ['device' => $device])->call('load')->call('setWiredSpeakerType', 'pl_1', 'Line')->assertStatus(403);

        $this->assertSame([], FakeWiredSpeakersDriver::$calls);
    }
}
