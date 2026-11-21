<?php

namespace Tests\Feature\Api;

use App\Domain\Library\LeadingSource;
use App\Domain\Stats\PlayRetention;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** /api/admin/library: settings and maintenance (docs/api/admin-library.md). */
class AdminLibraryApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['profile.current' => 'standard']);
    }

    public function test_every_route_needs_the_admin_token(): void
    {
        $this->assertNeedsAdminToken([['GET', '/api/admin/library/settings'], ['PUT', '/api/admin/library/settings'], ['POST', '/api/admin/library/maintenance/sync_genres']]);
    }

    public function test_settings_have_the_shape_of_the_library_form(): void
    {
        $this->getJson('/api/admin/library/settings', $this->adminHeaders())->assertOk()
            ->assertJsonStructure(['leading_source', 'leading', 'add_played_tracks', 'playback_loudness', 'nts_enabled', 'history_keep_months'])
            ->assertJsonPath('leading_source', 'auto')
            ->assertJsonPath('add_played_tracks', false)
            ->assertJsonPath('history_keep_months', null);
    }

    public function test_settings_change_only_the_keys_sent(): void
    {
        $headers = $this->adminHeaders();

        $this->putJson('/api/admin/library/settings', ['leading_source' => 'dlna', 'add_played_tracks' => true], $headers)->assertOk()
            ->assertJsonPath('leading_source', 'dlna')->assertJsonPath('leading', 'dlna')->assertJsonPath('add_played_tracks', true)->assertJsonPath('nts_enabled', false);

        $this->putJson('/api/admin/library/settings', ['nts_enabled' => true, 'playback_loudness' => true, 'history_keep_months' => 12], $headers)->assertOk()
            ->assertJsonPath('leading_source', 'dlna')->assertJsonPath('add_played_tracks', true)->assertJsonPath('nts_enabled', true)->assertJsonPath('playback_loudness', true);

        $this->assertSame('dlna', Setting::get(LeadingSource::SETTING));
        $this->assertSame(12, PlayRetention::months());

        $this->putJson('/api/admin/library/settings', ['add_played_tracks' => false, 'history_keep_months' => null], $headers)->assertOk()
            ->assertJsonPath('add_played_tracks', false)->assertJsonPath('history_keep_months', null);
    }

    public function test_settings_are_validated(): void
    {
        $headers = $this->adminHeaders();

        $this->putJson('/api/admin/library/settings', ['leading_source' => 'spotify'], $headers)->assertStatus(422);
        $this->putJson('/api/admin/library/settings', ['history_keep_months' => 0], $headers)->assertStatus(422);
        $this->putJson('/api/admin/library/settings', ['add_played_tracks' => 'maybe'], $headers)->assertStatus(422);
    }

    public function test_the_web_form_and_the_api_agree(): void
    {
        $this->actingAs(\App\Models\User::factory()->create())
            ->post('/settings/library', ['leading_source' => 'files', 'add_played_tracks' => '1'])->assertRedirect();

        $this->getJson('/api/admin/library/settings', $this->adminHeaders())
            ->assertJsonPath('leading_source', 'files')->assertJsonPath('add_played_tracks', true)->assertJsonPath('nts_enabled', false);
    }

    public function test_scan_and_verify_are_queued(): void
    {
        Queue::fake();
        $headers = $this->adminHeaders();

        $this->postJson('/api/admin/library/maintenance/scan_dlna', ['server' => '192.168.1.5'], $headers)->assertOk()
            ->assertJson(['status' => 'queued', 'command' => 'library:scan']);
        $this->postJson('/api/admin/library/maintenance/verify_files', [], $headers)->assertOk()->assertJson(['status' => 'queued', 'command' => 'library:verify-files']);
    }

    public function test_enrich_and_merge_run_as_dry_runs_and_merge_needs_a_confirmation(): void
    {
        $headers = $this->adminHeaders();

        $this->postJson('/api/admin/library/maintenance/enrich', ['dry_run' => true, 'limit' => 5, 'only' => 'metadata'], $headers)->assertOk()
            ->assertJsonPath('command', 'library:enrich')->assertJsonPath('status', 'ok');
        $this->postJson('/api/admin/library/maintenance/merge_duplicates', ['dry_run' => true], $headers)->assertOk()->assertJsonPath('command', 'library:merge-duplicates');
        $this->postJson('/api/admin/library/maintenance/merge_duplicates', [], $headers)->assertStatus(422)->assertJsonPath('error', 'confirmation_required');
        $this->postJson('/api/admin/library/maintenance/merge_duplicates', ['confirm' => true], $headers)->assertOk()->assertJsonPath('status', 'ok');
        $this->postJson('/api/admin/library/maintenance/sync_genres', [], $headers)->assertOk()->assertJsonPath('command', 'library:sync-genres');
        $this->postJson('/api/admin/library/maintenance/refresh_smart_playlists', [], $headers)->assertOk()->assertJsonPath('command', 'playlists:refresh-smart');
    }

    public function test_unknown_actions_and_bad_options_are_rejected(): void
    {
        $headers = $this->adminHeaders();

        $this->postJson('/api/admin/library/maintenance/format_disk', [], $headers)->assertNotFound();
        $this->postJson('/api/admin/library/maintenance/enrich', ['limit' => 0], $headers)->assertStatus(422);
        $this->postJson('/api/admin/library/maintenance/enrich', ['only' => 'everything'], $headers)->assertStatus(422);
    }

    public function test_it_is_not_available_in_the_control_profile(): void
    {
        config(['profile.current' => 'control']);

        $this->getJson('/api/admin/library/settings', $this->adminHeaders())->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
    }
}
