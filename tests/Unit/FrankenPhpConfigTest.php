<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The web server is FrankenPHP in dev and prod (docs/architecture/frankenphp.md). Supervisor expands %(ENV_CADDYFILE)s,
 * which only start-container sets; a program that reads a variable nobody exports stops supervisord at boot.
 */
class FrankenPhpConfigTest extends TestCase
{
    #[DataProvider('supervisorFiles')]
    public function test_the_php_program_runs_frankenphp_with_the_caddyfile_start_container_picks(string $file): void
    {
        $conf = file_get_contents(base_path($file));

        preg_match('/\[program:php\]([^\[]*)/s', $conf, $m);
        $this->assertStringContainsString('frankenphp run --config %(ENV_CADDYFILE)s', $m[1] ?? '');
        $this->assertStringNotContainsString('artisan serve', $conf);
        $this->assertStringContainsString('export CADDYFILE', file_get_contents(base_path('docker/8.4/start-container')));
    }

    public static function supervisorFiles(): array
    {
        return ['dev' => ['docker/8.4/supervisord.conf'], 'prod' => ['docker/8.4/supervisord.prod.conf']];
    }

    #[DataProvider('modes')]
    public function test_start_container_exports_the_right_caddyfile(string $mode, string $profile, string $expected): void
    {
        // Run the mode block of start-container up to supervisord, with supervisord replaced by a print.
        $script = file_get_contents(base_path('docker/8.4/start-container'));
        $this->assertSame(1, preg_match('/(# FRANKENPHP_MODE.*?)exec \/usr\/bin\/supervisord/s', $script, $m));

        $result = Process::env(['FRANKENPHP_MODE' => $mode, 'PROFILE' => $profile])
            ->run(['bash', '-c', $m[1].'echo "$CADDYFILE $FRANKENPHP_WORKERS $MAX_REQUESTS"']);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame($expected.' 2 500', trim($result->output()));
    }

    public static function modes(): array
    {
        return [
            'classic is the default' => ['', 'standard', '/etc/caddy/Caddyfile'],
            'classic' => ['classic', 'full', '/etc/caddy/Caddyfile'],
            'worker in standard' => ['worker', 'standard', '/etc/caddy/Caddyfile.worker'],
            'worker in full' => ['worker', 'full', '/etc/caddy/Caddyfile.worker'],
            'worker is refused in control' => ['worker', 'control', '/etc/caddy/Caddyfile'],
            'an unknown mode is classic' => ['nonsense', 'standard', '/etc/caddy/Caddyfile'],
        ];
    }

    public function test_the_worker_files_exist_and_the_image_and_compose_files_ship_them(): void
    {
        foreach (['Caddyfile', 'Caddyfile.worker', 'Caddyfile.dev', 'Caddyfile.dev.worker'] as $file) {
            $this->assertFileExists(base_path("docker/frankenphp/{$file}"));
        }
        $this->assertFileExists(base_path('public/frankenphp-worker.php'));
        $this->assertStringContainsString('Caddyfile.worker', file_get_contents(base_path('docker/frankenphp/Dockerfile')));
        $this->assertStringContainsString('Caddyfile.dev.worker:/etc/caddy/Caddyfile.worker', file_get_contents(base_path('compose.yaml')));
    }
}
