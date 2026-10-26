<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SpotifyTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin enters the credentials of their own Spotify app (docs/architecture/spotify-app-setup.md). */
class SpotifyCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'abcdef0123456789abcdef0123456789';

    private const SECRET = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config(['spotify.auth.client_id' => null, 'spotify.auth.client_secret' => null]);
    }

    public function test_credentials_are_stored_with_the_secret_encrypted(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('spotify.credentials'), ['client_id' => self::ID, 'client_secret' => self::SECRET])
            ->assertRedirect(route('settings.spotify'));

        $this->assertSame(self::ID, SpotifyTokenService::clientId());
        $this->assertSame(self::SECRET, SpotifyTokenService::clientSecret());
        $this->assertNotSame(self::SECRET, Setting::get('spotify_client_secret'));
        $this->assertTrue(SpotifyTokenService::hasCredentials());
    }

    public function test_guests_cannot_save_them(): void
    {
        $this->post(route('spotify.credentials'), ['client_id' => self::ID, 'client_secret' => self::SECRET])
            ->assertRedirect();

        $this->assertFalse(SpotifyTokenService::hasCredentials());
    }

    public function test_the_secret_is_required_the_first_time_and_optional_after(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('spotify.credentials'), ['client_id' => self::ID])->assertSessionHasErrors('client_secret');

        SpotifyTokenService::saveCredentials(self::ID, self::SECRET);
        $this->actingAs($admin)->post(route('spotify.credentials'), ['client_id' => self::ID])->assertSessionHasNoErrors();
        $this->assertSame(self::SECRET, SpotifyTokenService::clientSecret());
    }

    public function test_a_new_app_drops_the_old_connection(): void
    {
        SpotifyTokenService::saveCredentials(self::ID, self::SECRET);
        Setting::set('spotify_refresh_token', 'r');

        SpotifyTokenService::saveCredentials(self::ID, null);
        $this->assertNotNull(Setting::get('spotify_refresh_token'));

        SpotifyTokenService::saveCredentials('zzzzzzzz0123456789zzzzzzzz', self::SECRET);
        $this->assertNull(Setting::get('spotify_refresh_token'));
    }

    public function test_env_credentials_still_work_and_connecting_without_any_goes_to_settings(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('spotify.authorize'))->assertRedirect(route('settings.spotify'));

        config(['spotify.auth.client_id' => self::ID, 'spotify.auth.client_secret' => self::SECRET]);
        $this->assertTrue(SpotifyTokenService::hasCredentials());
        $this->actingAs($admin)->get(route('settings.spotify'))->assertOk()->assertSee('Your Spotify app');
    }
}
