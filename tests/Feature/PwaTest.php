<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Installable web app (8.6): manifest, service worker, offline page, layout tags, notification glue. */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_standalone_with_icons_that_exist(): void
    {
        $response = $this->get('/manifest.webmanifest')->assertOk();
        $this->assertStringStartsWith('application/manifest+json', $response->headers->get('Content-Type'));

        $manifest = $response->json();
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('ReMoment', $manifest['short_name']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertSame('#0c0a09', $manifest['theme_color']);
        $this->assertSame($manifest['theme_color'], $manifest['background_color']);
        $this->assertContains('512x512', array_column($manifest['icons'], 'sizes'));
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertFileExists(public_path('icons/icon-180.png'));
    }

    public function test_service_worker_is_served_from_the_root_and_never_cached(): void
    {
        $response = $this->get('/sw.js')->assertOk();
        $this->assertStringStartsWith('text/javascript', $response->headers->get('Content-Type'));
        $this->assertSame('/', $response->headers->get('Service-Worker-Allowed'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));

        // Judge the code, not the comments that explain what it leaves alone.
        $source = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $response->getContent());
        $this->assertStringNotContainsString('__SHELL_VERSION__', $source);
        // Only the offline page and the icons are stored; dynamic routes are never touched.
        $this->assertStringContainsString("const PRECACHE = ['/offline', '/icons/icon-192.png', '/icons/icon-512.png'];", $source);
        $this->assertSame(1, substr_count($source, 'cache.addAll'));
        $this->assertSame(0, substr_count($source, 'cache.put'));
        $this->assertStringNotContainsString('/api', $source);
        $this->assertStringNotContainsString('livewire', strtolower($source));
        $this->assertStringNotContainsString('artwork', $source);
    }

    public function test_offline_page_renders_without_a_session(): void
    {
        $response = $this->get('/offline')->assertOk()->assertSee("The server can't be reached", false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_routes_do_not_depend_on_the_profile(): void
    {
        foreach (['control', 'standard', 'full'] as $profile) {
            config(['profile.current' => $profile]);
            foreach (['/manifest.webmanifest', '/sw.js', '/offline'] as $uri) {
                $this->get($uri)->assertOk();
            }
        }
    }

    public function test_layout_links_the_manifest_and_loads_the_notification_script(): void
    {
        $this->get('/devices')->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('name="theme-color"', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('js/remoment-pwa.js', false);
    }

    public function test_health_page_has_the_opt_in_toggle_and_asks_nothing_on_load(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/health')->assertOk()
            ->assertSee('data-test="alert-notifications-toggle"', false);

        $js = file_get_contents(public_path('js/remoment-pwa.js'));
        // The permission prompt exists only inside the toggle (a click), never at load.
        $this->assertSame(1, substr_count($js, 'requestPermission()'));
        $this->assertStringContainsString('async toggle()', $js);
        $this->assertStringContainsString("const LEVELS = ['warning', 'critical'];", $js);
        $this->assertStringContainsString("client.subscribe('remoment/alerts/+')", file_get_contents(public_path('js/remoment-live.js')));
    }
}
