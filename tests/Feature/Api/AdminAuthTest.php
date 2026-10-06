<?php

namespace Tests\Feature\Api;

use App\Models\AdminToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Token login of the one shared admin for apps (docs/api/admin-auth.md). */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('admin-api-login|127.0.0.1');
    }

    private function login(string $password = 'password', ?string $name = 'Remko\'s iPhone'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/login', array_filter(['password' => $password, 'device_name' => $name]));
    }

    public function test_the_admin_can_sign_in_with_the_password_only(): void
    {
        User::factory()->create();

        $response = $this->login()->assertOk()->assertJsonStructure(['token', 'token_id', 'name']);

        $this->assertStringStartsWith('rmt_', $response->json('token'));
        $this->assertSame('Remko\'s iPhone', $response->json('name'));
        $this->assertDatabaseCount('admin_tokens', 1);
    }

    public function test_the_token_is_stored_hashed_only(): void
    {
        User::factory()->create();
        $plain = $this->login()->json('token');

        $this->assertDatabaseMissing('admin_tokens', ['token' => $plain]);
        $this->assertDatabaseHas('admin_tokens', ['token' => hash('sha256', $plain)]);
        $this->assertArrayNotHasKey('token', AdminToken::first()->toArray());
    }

    public function test_a_wrong_password_is_unauthorized_and_issues_nothing(): void
    {
        User::factory()->create();

        $this->login('wrong')->assertUnauthorized()->assertJsonPath('error', 'invalid_credentials');

        $this->assertDatabaseCount('admin_tokens', 0);
    }

    public function test_signing_in_without_an_admin_is_unauthorized(): void
    {
        $this->login()->assertUnauthorized()->assertJsonPath('error', 'invalid_credentials');
    }

    public function test_the_password_is_required(): void
    {
        User::factory()->create();

        $this->postJson('/api/admin/login', [])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_repeated_wrong_passwords_are_throttled(): void
    {
        User::factory()->create();

        foreach (range(1, 5) as $_) {
            $this->login('wrong')->assertUnauthorized();
        }

        $this->login('wrong')->assertStatus(429)->assertJsonPath('error', 'too_many_attempts')->assertHeader('Retry-After');
        // Even the right password waits.
        $this->login()->assertStatus(429);
    }

    public function test_a_good_sign_in_resets_the_throttle(): void
    {
        User::factory()->create();

        foreach (range(1, 4) as $_) {
            $this->login('wrong')->assertUnauthorized();
        }
        $this->login()->assertOk();

        foreach (range(1, 4) as $_) {
            $this->login('wrong')->assertUnauthorized();
        }
    }

    public function test_admin_routes_need_a_valid_token(): void
    {
        User::factory()->create();

        $this->getJson('/api/admin/me')->assertUnauthorized()->assertJsonPath('error', 'unauthenticated')->assertHeader('WWW-Authenticate', 'Bearer');
        $this->getJson('/api/admin/me', ['Authorization' => 'Bearer rmt_nope'])->assertUnauthorized();
        $this->getJson('/api/admin/me', ['Authorization' => 'Basic abc'])->assertUnauthorized();
    }

    public function test_a_token_opens_the_admin_routes_and_records_its_use(): void
    {
        User::factory()->create();
        $token = $this->login()->json('token');

        $this->getJson('/api/admin/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('name', 'Remko\'s iPhone');

        $this->assertNotNull(AdminToken::first()->last_used_at);
        $this->assertSame('127.0.0.1', AdminToken::first()->last_used_ip);
    }

    public function test_signing_out_revokes_only_that_token(): void
    {
        User::factory()->create();
        $phone = $this->login(name: 'phone')->json('token');
        $ipad = $this->login(name: 'ipad')->json('token');

        $this->deleteJson('/api/admin/token', [], ['Authorization' => "Bearer {$phone}"])->assertOk();

        $this->getJson('/api/admin/me', ['Authorization' => "Bearer {$phone}"])->assertUnauthorized();
        $this->getJson('/api/admin/me', ['Authorization' => "Bearer {$ipad}"])->assertOk();
    }

    public function test_changing_the_password_signs_every_app_out(): void
    {
        $admin = User::factory()->create();
        $token = $this->login()->json('token');

        $this->actingAs($admin)->put('/password', [
            'current_password' => 'password',
            'password' => 'a-new-Password-123',
            'password_confirmation' => 'a-new-Password-123',
        ])->assertSessionHasNoErrors();

        $this->getJson('/api/admin/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->assertDatabaseCount('admin_tokens', 0);
    }

    public function test_the_admin_can_sign_an_app_out_from_the_web(): void
    {
        $admin = User::factory()->create();
        $token = $this->login()->json('token');

        $this->actingAs($admin)->get('/settings/users')->assertOk()->assertSee('Remko&#039;s iPhone', false);

        $this->actingAs($admin)->delete('/settings/app-tokens/'.AdminToken::first()->id)->assertRedirect(route('settings.users'));

        $this->getJson('/api/admin/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_the_open_api_stays_open(): void
    {
        $this->getJson('/api/info')->assertOk();
        $this->getJson('/api/devices')->assertOk();
    }
}
