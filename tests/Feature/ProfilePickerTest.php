<?php

namespace Tests\Feature;

use App\Livewire\ProfilePicker;
use App\Livewire\SetupWizard;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfilePickerTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $profile): void
    {
        config(['profile.current' => $profile]);
    }

    public function test_the_three_cards_show_what_runs_what_it_suits_and_needs(): void
    {
        $this->actingAs(User::factory()->create());
        $this->profile('standard');

        Livewire::test(ProfilePicker::class)
            ->assertSeeInOrder(['Control', 'Standard', 'Full'])
            ->assertSee('Raspberry Pi 2 and up')
            ->assertSee('SQLite (a file in a named volume)')
            ->assertSee('MariaDB')
            ->assertSee('Running')
            ->assertSee('Keep Standard')
            ->assertSee('Choose Control');
    }

    public function test_choosing_stores_a_setting_and_a_guest_cannot(): void
    {
        $this->profile('standard');

        Livewire::test(ProfilePicker::class)->call('choose', 'full')->assertStatus(403);
        $this->assertNull(Setting::get('profile_chosen'));

        $this->actingAs(User::factory()->create());
        Livewire::test(ProfilePicker::class)->call('choose', 'full');
        $this->assertSame('full', Setting::get('profile_chosen'));

        Livewire::test(ProfilePicker::class)->call('choose', 'bogus');
        $this->assertSame('full', Setting::get('profile_chosen'));
    }

    public function test_when_the_choice_differs_the_environment_wins_and_the_page_says_what_to_change(): void
    {
        $this->actingAs(User::factory()->create());
        $this->profile('control');
        Setting::set('profile_chosen', 'standard');

        Livewire::test(ProfilePicker::class)
            ->assertSee('in the environment wins')
            ->assertSee('PROFILE=standard')
            ->assertSee('profile:upgrade')
            ->assertSee('COMPOSE_PROFILES=db')
            ->assertSee('Chosen');
    }

    public function test_moving_down_is_explained_as_not_supported(): void
    {
        $this->actingAs(User::factory()->create());
        $this->profile('full');
        Setting::set('profile_chosen', 'control');

        Livewire::test(ProfilePicker::class)->assertSee('not supported')->assertDontSee('profile:upgrade');
    }

    public function test_a_matching_choice_is_confirmed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->profile('standard');
        Setting::set('profile_chosen', 'standard');

        Livewire::test(ProfilePicker::class)->assertSee('runs Standard, as chosen')->assertDontSee('wins over this choice');
    }

    public function test_the_wizard_shows_the_picker_and_skips_the_library_step_in_control(): void
    {
        $this->actingAs(User::factory()->create());
        $this->profile('control');
        Livewire::test(SetupWizard::class)
            ->assertSee('0 of 2 steps done')
            ->assertSee('Choose Standard')
            ->assertDontSee('Set up your library');

        $this->profile('standard');
        Livewire::test(SetupWizard::class)->assertSee('0 of 3 steps done')->assertSee('Set up your library');
    }

    public function test_the_settings_page_needs_the_admin_and_lists_the_capabilities(): void
    {
        $this->get('/settings/profile')->assertRedirect();

        $this->actingAs(User::factory()->create());
        $this->profile('control');
        $this->get('/settings/profile')->assertOk()
            ->assertSee('What each profile runs')
            ->assertSee(config('profile.features.analysis.label'))
            ->assertSee('needs a restart of the stack');
        $this->get('/settings')->assertOk()->assertSee('Profile')->assertDontSee('Audio analysis');
    }
}
