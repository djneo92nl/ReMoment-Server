<?php

namespace Tests\Feature;

use App\Domain\System\InstalledVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    private function configValue(): string
    {
        return (require dirname(__DIR__, 2).'/config/app.php')['version'];
    }

    public function test_config_reads_the_version_file(): void
    {
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), $this->configValue());
        $this->assertSame('1.0.0-dev', trim(file_get_contents(base_path('VERSION'))));
        $this->assertSame($this->configValue(), config('app.version'));
    }

    public function test_missing_file_falls_back(): void
    {
        $dir = sys_get_temp_dir().'/remoment-version-'.uniqid();
        mkdir($dir);
        $this->app->setBasePath($dir);

        try {
            $this->assertSame('0.0.0-unknown', $this->configValue());
            file_put_contents($dir.'/VERSION', "  2.3.4\n");
            $this->assertSame('2.3.4', $this->configValue());
        } finally {
            @unlink($dir.'/VERSION');
            @rmdir($dir);
            $this->app->setBasePath(dirname(__DIR__, 2));
        }
    }

    public function test_info_reports_the_same_value(): void
    {
        $this->getJson('/api/info')->assertOk()->assertJsonPath('version', config('app.version'));
    }

    public function test_command_json_shape(): void
    {
        $this->artisan('app:version', ['--json' => true])->assertSuccessful();

        $this->artisan('app:version')->expectsOutput(config('app.version'))->assertSuccessful();
    }

    public function test_json_output_content(): void
    {
        \Illuminate\Support\Facades\Artisan::call('app:version', ['--json' => true]);
        $data = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

        $this->assertSame(['version', 'recorded', 'profile'], array_keys($data));
        $this->assertSame(config('app.version'), $data['version']);
        $this->assertNull($data['recorded']);
        $this->assertSame(\App\Domain\Profile\Profile::current(), $data['profile']);
    }

    public function test_record_and_read_back(): void
    {
        $this->assertNull(InstalledVersion::recorded());

        $this->artisan('app:version', ['--record' => true])->assertSuccessful();

        $this->assertSame(config('app.version'), InstalledVersion::recorded());
    }
}
