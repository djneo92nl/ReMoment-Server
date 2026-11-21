<?php

namespace Tests\Feature;

use App\Domain\Scenes\SceneOperations;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SignsInAsAdmin;
use Tests\TestCase;

/** GET /api/admin/ha/package and the button on /settings/mqtt: the same YAML as `ha:package` (docs/api/admin-ha.md). */
class HaPackageDownloadTest extends TestCase
{
    use RefreshDatabase;
    use SignsInAsAdmin;

    public function test_the_api_needs_the_admin_token(): void
    {
        $this->assertNeedsAdminToken([['GET', '/api/admin/ha/package']]);
    }

    public function test_it_returns_the_yaml_of_the_command_as_a_download(): void
    {
        $response = $this->get('/api/admin/ha/package?base_url=http://remoment.test/', $this->adminHeaders())->assertOk();

        $this->assertStringStartsWith('application/yaml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="remoment.yaml"', $response->headers->get('Content-Disposition'));

        Artisan::call('ha:package', ['--base-url' => 'http://remoment.test/']);
        $this->assertSame(Artisan::output(), $response->getContent());

        $commands = Yaml::parse($response->getContent())['rest_command'];
        $this->assertCount(count((new SceneOperations)->all()), $commands);
        foreach ($commands as $command) {
            $this->assertStringStartsWith('http://remoment.test/api/', $command['url']);
        }
    }

    public function test_the_default_base_url_is_app_url(): void
    {
        config(['app.url' => 'http://pi.lan:8080']);

        $this->get('/api/admin/ha/package', $this->adminHeaders())->assertOk()->assertSee("url: 'http://pi.lan:8080/api/", false);
    }

    public function test_a_bad_base_url_is_422(): void
    {
        $this->getJson('/api/admin/ha/package?base_url=javascript:alert(1)', $this->adminHeaders())->assertStatus(422)->assertJsonValidationErrors('base_url');
    }

    public function test_the_settings_page_offers_the_download_and_the_file_is_the_same(): void
    {
        $this->get('/settings/mqtt')->assertRedirect();

        $this->actingAs(User::factory()->create());
        $this->get('/settings/mqtt')->assertOk()->assertSee('data-test="ha-package-download"', false);

        $response = $this->get('/settings/mqtt/home-assistant/package?base_url=http://remoment.test')->assertOk();
        $this->assertStringContainsString('filename="remoment.yaml"', $response->headers->get('Content-Disposition'));
        $this->assertArrayHasKey('rest_command', Yaml::parse($response->getContent()));
        $this->assertStringContainsString('http://remoment.test/api/', $response->getContent());
    }

    public function test_the_web_download_is_admin_only(): void
    {
        $this->get('/settings/mqtt/home-assistant/package')->assertRedirect();
    }
}
