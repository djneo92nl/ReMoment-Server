<?php

namespace Tests\Feature;

use App\Livewire\SetupWizard;
use App\Models\Device;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_zero_of_three_steps_done_by_default(): void
    {
        Livewire::test(SetupWizard::class)
            ->assertSee('0 of 3 steps done');
    }

    public function test_devices_step_completes_once_a_device_exists(): void
    {
        Device::factory()->create([
            'ip_address' => '10.0.0.1',
            'device_name' => 'Test Speaker',
            'device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class,
        ]);

        Livewire::test(SetupWizard::class)
            ->assertSee('1 of 3 steps done')
            ->assertSee('1 device added.');
    }

    public function test_skipping_clients_step_persists_across_a_fresh_mount(): void
    {
        Livewire::test(SetupWizard::class)
            ->call('skipClients')
            ->assertSee('1 of 3 steps done');

        // Simulate revisiting the page later: a brand new component instance
        // should still see the skip as done, since it's stored in Setting.
        Livewire::test(SetupWizard::class)
            ->assertSee('1 of 3 steps done');
    }

    public function test_skipping_library_step_marks_it_done(): void
    {
        Livewire::test(SetupWizard::class)
            ->call('skipLibrary')
            ->assertSee('1 of 3 steps done');
    }

    public function test_finish_button_only_appears_once_all_steps_are_done(): void
    {
        Livewire::test(SetupWizard::class)
            ->assertSee("Skip setup, I'll do this later")
            ->assertDontSee('Finish');

        Device::factory()->create([
            'ip_address' => '10.0.0.1',
            'device_name' => 'Test Speaker',
            'device_driver' => \App\Integrations\Spotify\MusicPlayerDriver::class,
        ]);

        Livewire::test(SetupWizard::class)
            ->call('skipClients')
            ->call('skipLibrary')
            ->assertSee('3 of 3 steps done')
            ->assertSee('Finish')
            ->assertDontSee("Skip setup, I'll do this later");
    }

    public function test_finish_sets_the_dismissal_flag_and_redirects_to_devices(): void
    {
        Livewire::test(SetupWizard::class)
            ->call('finish')
            ->assertRedirect(route('devices.index'));

        $this->assertNotNull(Setting::get('setup_wizard_dismissed'));
    }
}
