<?php

namespace Tests\Feature;

use App\Domain\Alerts\Alerts;
use App\Domain\Credentials\ServiceCredentials;
use App\Domain\Diagnostics\DiagnosticsBundle;
use App\Domain\Health\ExternalServices;
use App\Domain\System\UpgradeHistory;
use App\Models\AdminToken;
use App\Models\Device;
use App\Models\DeviceMeta;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class DiagnosticsBundleTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/remoment-diag-'.uniqid();
        mkdir($this->dir, 0775, true);
        $this->log = storage_path('logs/laravel.log');
        @unlink($this->log);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        @unlink($this->log);
        parent::tearDown();
    }

    private const SECRETS = ['SPOTIFYSECRETVALUE', 'LASTFMSESSIONVALUE', 'ADMINPASSWORD-XYZ', 'FAILEDJOBSECRET', '192.168.77.5', '00:11:22:33:44:55', 'DEVICEMETASECRET', 'ALERTSECRET', 'LOGSECRETTOKEN'];

    private function seedSecrets(): void
    {
        config(['app.key' => 'base64:APPKEYSECRETVALUE0123456789abcdefghijklmnop=']);
        Setting::set('spotify_access_token', 'SPOTIFYSECRETVALUE');
        Setting::set('lastfm_session_key', 'LASTFMSESSIONVALUE');
        User::factory()->create(['email' => 'admin@admin.com', 'password' => bcrypt('ADMINPASSWORD-XYZ')]);
        AdminToken::issue('phone');

        $device = Device::factory()->create(['ip_address' => '192.168.77.5', 'device_name' => 'Living room', 'device_brand_name' => 'Bang & Olufsen', 'device_product_type' => 'Beolab 28']);
        DeviceMeta::create(['device_id' => $device->id, 'key' => 'ase_jid', 'value' => 'DEVICEMETASECRET@00:11:22:33:44:55']);
        Cache::put("device:{$device->id}:last_seen", now()->toIso8601String(), 3600);

        Alerts::raise('device.offline', 'k1', 'warning', 'Offline at 192.168.77.5', 'api_key=ALERTSECRET', $device->id, ['token' => 'ALERTSECRET']);

        DB::table('failed_jobs')->insert([
            'uuid' => 'u1', 'connection' => 'redis', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Thing', 'data' => ['secret' => 'FAILEDJOBSECRET']]),
            'exception' => "RuntimeException: boom at 192.168.77.5\n#0 FAILEDJOBSECRET in file.php", 'failed_at' => now(),
        ]);

        UpgradeHistory::add('1.0.0', '1.0.1', 'ok', 'backup-192.168.77.5.zip');
        file_put_contents($this->log, "[2026-11-14] local.ERROR: call failed Authorization: Bearer LOGSECRETTOKEN host 192.168.77.5 mac 00:11:22:33:44:55\n");
    }

    private function zipText(string $path): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= "\n=== ".$zip->getNameIndex($i)."\n".$zip->getFromIndex($i);
        }
        $zip->close();

        return $all;
    }

    public function test_the_bundle_has_the_sections_and_no_secret_anywhere(): void
    {
        $this->seedSecrets();
        $path = $this->dir.'/b.zip';

        $this->artisan('diagnostics:bundle', ['--path' => $path])->assertSuccessful();

        $text = $this->zipText($path);
        foreach (['README.txt', 'system.json', 'heartbeats.json', 'queues.json', 'devices.json', 'alerts.json', 'upgrade-history.json', 'laravel.log'] as $name) {
            $this->assertStringContainsString("=== {$name}", $text);
        }
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $text, "{$secret} leaked into the bundle");
        }
        foreach (['APPKEYSECRETVALUE', ServiceCredentials::class, 'Living room', 'Bearer LOGSECRETTOKEN'] as $leak) {
            $this->assertStringNotContainsString($leak, $text);
        }
        // The useful parts survive.
        $this->assertStringContainsString('Beolab 28', $text);
        $this->assertStringContainsString('ase_jid', $text);
        $this->assertStringContainsString('RuntimeException: boom', $text);
        $this->assertStringContainsString('"job": "Thing"', $text);
        $this->assertStringContainsString('call failed', $text);
        $this->assertStringContainsString('"profile"', $text);
    }

    public function test_external_service_results_are_redacted_too(): void
    {
        Cache::put(ExternalServices::CACHE_KEY, ['at' => now()->toIso8601String(), 'results' => ['x' => ['name' => 'X', 'group' => 'g', 'status' => 'error', 'message' => 'GET http://10.1.2.3/?api_key=SERVICESECRETKEY failed']]]);
        $path = $this->dir.'/b.zip';

        DiagnosticsBundle::make()->write($path);

        $text = $this->zipText($path);
        $this->assertStringContainsString('failed', $text);
        $this->assertStringNotContainsString('SERVICESECRETKEY', $text);
        $this->assertStringNotContainsString('10.1.2.3', $text);
    }

    public function test_the_control_profile_skips_library_sections_cleanly(): void
    {
        config(['profile.current' => 'control']);
        $files = DiagnosticsBundle::make()->files();

        $this->assertArrayHasKey('system.json', $files);
        $this->assertArrayNotHasKey('external-services.json', $files);
        $this->assertStringContainsString('Control', $files['README.txt']);
    }

    public function test_the_log_tail_is_limited_to_500_lines(): void
    {
        file_put_contents($this->log, implode("\n", array_map(fn ($i) => "line-{$i}", range(1, 700)))."\n");

        $log = DiagnosticsBundle::make()->files()['laravel.log'];

        $this->assertStringNotContainsString('line-200'."\n", $log);
        $this->assertStringContainsString('line-700', $log);
        $this->assertStringContainsString('line-201'."\n", $log);
    }

    public function test_the_download_needs_the_admin_session_or_token(): void
    {
        User::factory()->create();
        [, $token] = AdminToken::issue('t');

        $this->get('/api/admin/diagnostics', ['Accept' => 'application/json'])->assertUnauthorized();
        $this->get('/settings/health/diagnostics')->assertRedirect();

        $api = $this->get('/api/admin/diagnostics', ['Authorization' => 'Bearer '.$token]);
        $api->assertOk();
        $this->assertSame('application/zip', $api->headers->get('Content-Type'));

        $this->actingAs(User::first())->get('/settings/health/diagnostics')->assertOk();
    }
}
