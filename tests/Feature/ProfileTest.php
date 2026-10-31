<?php

namespace Tests\Feature;

use App\Domain\Profile\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $profile): void
    {
        config(['profile.current' => $profile]);
    }

    public function test_the_active_profile_is_read_from_config_and_normalised(): void
    {
        $this->profile('Control ');
        $this->assertSame('control', Profile::current());
        $this->assertTrue(Profile::is('control'));
        $this->assertFalse(Profile::is('standard', 'full'));
    }

    public function test_an_unknown_value_falls_back_to_standard(): void
    {
        Log::spy();
        $this->profile('nonsense');

        $this->assertSame('standard', Profile::current());
    }

    public function test_the_capability_table(): void
    {
        $this->profile('control');
        $this->assertTrue(Profile::allows('devices'));
        $this->assertFalse(Profile::allows('library'));
        $this->assertFalse(Profile::allows('analysis'));

        $this->profile('standard');
        $this->assertTrue(Profile::allows('library'));
        $this->assertTrue(Profile::allows('catalogue'));
        $this->assertFalse(Profile::allows('analysis'));
        $this->assertFalse(Profile::allows('deep_analysis'));

        $this->profile('full');
        $this->assertTrue(Profile::allows('analysis'));
        $this->assertTrue(Profile::allows('rip_upload'));
    }

    public function test_an_unknown_feature_is_a_bug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Profile::allows('libary');
    }

    public function test_every_profile_runs_the_base_features(): void
    {
        foreach (Profile::ALL as $profile) {
            foreach (['devices', 'history', 'radio', 'alerts'] as $feature) {
                $this->assertTrue(Profile::allowsIn($profile, $feature), "{$profile} lacks {$feature}");
            }
        }
    }

    public function test_features_nest_control_in_standard_in_full(): void
    {
        $control = Profile::featuresOf('control');
        $standard = Profile::featuresOf('standard');
        $full = Profile::featuresOf('full');

        $this->assertSame([], array_diff($control, $standard));
        $this->assertSame([], array_diff($standard, $full));
    }

    public function test_the_lowest_profile_for_a_feature(): void
    {
        $this->assertSame('standard', Profile::lowestFor('library'));
        $this->assertSame('full', Profile::lowestFor('analysis'));
        $this->assertSame('control', Profile::lowestFor('devices'));
    }

    public function test_every_gated_route_row_names_a_known_feature(): void
    {
        foreach (array_keys(config('profile.routes')) as $feature) {
            $this->assertNotNull(config("profile.features.{$feature}"), $feature);
        }
    }

    public function test_the_play_history_has_its_own_queue_in_control_only(): void
    {
        $this->profile('control');
        $this->assertSame('history', Profile::historyQueue());

        $this->profile('standard');
        $this->assertNull(Profile::historyQueue());
    }

    public function test_info_reports_the_profile(): void
    {
        foreach (Profile::ALL as $profile) {
            $this->profile($profile);
            $this->getJson('/api/info')->assertOk()->assertJsonPath('features.profile', $profile)->assertJsonPath('features.alerts', true);
        }
    }

    // --- Route gate --------------------------------------------------------------------

    public function test_control_answers_422_not_available_in_profile_for_library_api_routes(): void
    {
        $this->profile('control');

        foreach (['/api/library/albums', '/api/library/artists', '/api/library/wishlist', '/api/library/health', '/api/library/tracks/1/deep-analysis'] as $url) {
            $this->getJson($url)->assertStatus(422)
                ->assertJsonPath('error', 'not_available_in_profile')
                ->assertJsonPath('profile', 'control');
        }

        $this->postJson('/api/devices/1/library/play', ['track_id' => 1])->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->getJson('/api/library/albums')->assertJsonPath('message', fn ($m) => str_contains($m, 'Control profile'));
    }

    public function test_control_keeps_devices_radio_alerts_and_info_open(): void
    {
        $this->profile('control');

        foreach (['/api/devices', '/api/alerts', '/api/info', '/api/sync-groups'] as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_standard_runs_the_library_but_not_analysis_or_deep_analysis(): void
    {
        $this->profile('standard');

        $this->getJson('/api/library/albums')->assertOk();
        $this->getJson('/api/library/health')->assertStatus(422)->assertJsonPath('required_profile', 'full');
        $this->getJson('/api/library/tracks/1/deep-analysis')->assertStatus(422)->assertJsonPath('feature', 'deep_analysis');
    }

    public function test_full_runs_everything(): void
    {
        $this->profile('full');

        $this->getJson('/api/library/albums')->assertOk();
        $this->getJson('/api/library/health')->assertOk();
    }

    public function test_default_profile_behaves_like_a_pre_profile_install(): void
    {
        $this->profile('standard');

        foreach (['/api/library/albums', '/api/library/wishlist', '/api/library/artists'] as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_control_redirects_library_pages_to_the_notice(): void
    {
        $this->profile('control');

        foreach (['/library' => 'library', '/artists' => 'library', '/albums/1' => 'library', '/library/wishlist' => 'catalogue', '/stream/1' => 'file_storage'] as $url => $feature) {
            $this->get($url)->assertRedirect(route('profile.unavailable', ['feature' => $feature]));
        }

        $this->get('/devices')->assertOk();
        $this->get('/history')->assertOk();
        $this->get('/radio')->assertOk();
    }

    public function test_the_notice_names_the_profile_and_what_would_run_the_feature(): void
    {
        $this->profile('control');

        $this->get('/not-in-profile/library')->assertOk()->assertSee('Control profile')->assertSee('Standard or Full');
        $this->get('/not-in-profile/nope')->assertNotFound();
    }

    public function test_the_notice_is_not_a_redirect_loop_in_any_profile(): void
    {
        foreach (Profile::ALL as $profile) {
            $this->profile($profile);
            $this->get('/not-in-profile')->assertOk();
        }
    }
}
