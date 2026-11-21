<?php

namespace Tests\Feature;

use App\Domain\Tools\Tools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Tools entry of the menu and the /tools page (docs/frontend/tools-menu.md). */
class ToolsMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_menu_has_a_tools_entry_that_opens_the_hub_with_a_card_per_tool(): void
    {
        $this->get('/devices')->assertOk()->assertSee('href="'.route('tools.index').'"', false);

        $this->get('/tools')->assertOk()->assertSee('data-test="tools"', false)
            ->assertSee('data-tool="mixer"', false)->assertSee('href="'.route('mixer').'"', false);
    }

    public function test_the_tools_sub_menu_opens_on_the_hub_and_on_a_tool_page(): void
    {
        // On other pages only the Tools entry shows; the mixer link appears once you are in Tools.
        $this->get('/devices')->assertDontSee('href="'.route('mixer').'"', false);
        $this->get('/tools')->assertSee('aria-current="page"', false);
        $this->get('/mixer')->assertOk()->assertSee('href="'.route('tools.index').'"', false);
    }

    public function test_standard_has_the_export_tool_but_not_the_mixer(): void
    {
        config(['profile.current' => 'standard']);

        $this->assertSame(['export', 'sync'], array_column(Tools::available(), 'key'));
        $this->get('/tools')->assertOk()->assertSee('data-tool="export"', false)->assertDontSee('data-tool="mixer"', false);
        $this->get('/mixer')->assertRedirectContains('/not-in-profile');
    }

    public function test_without_a_tool_the_profile_has_no_menu_entry_and_the_hub_says_so(): void
    {
        config(['profile.current' => 'control']);

        $this->assertSame([], Tools::available());
        $this->get('/devices')->assertOk()->assertDontSee('href="'.route('tools.index').'"', false);
        $this->get('/tools')->assertOk()->assertSee('No tools in this profile');
        $this->get('/tools/export')->assertRedirectContains('/not-in-profile');
    }

    public function test_the_export_page_explains_and_asks_a_guest_to_sign_in_and_shows_the_list_to_the_admin(): void
    {
        $this->get('/tools/export')->assertOk()->assertSee('data-test="export-how"', false)
            ->assertSee('needs the admin login')->assertDontSee('No exports yet');

        $this->actingAs(\App\Models\User::factory()->create());

        $this->get('/tools/export')->assertOk()->assertSee('No exports yet')->assertDontSee('needs the admin login');
    }

    public function test_every_tool_names_a_real_route_and_a_profile_feature(): void
    {
        foreach (Tools::all() as $tool) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($tool['route']), $tool['key'].' route');
            $this->assertArrayHasKey($tool['feature'], config('profile.features'), $tool['key'].' feature');
        }
    }
}
