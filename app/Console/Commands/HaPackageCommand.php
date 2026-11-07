<?php

namespace App\Console\Commands;

use App\Domain\Scenes\HaPackage;
use Illuminate\Console\Command;

class HaPackageCommand extends Command
{
    protected $signature = 'ha:package {--output= : Write the YAML to this file instead of stdout} {--base-url= : The server origin HA calls (default APP_URL, else http://remoment.local)}';

    protected $description = 'Generate the Home Assistant rest_command package for the scene-capable operations (config/scene-operations.php, docs/api/openapi.yaml)';

    public function handle(HaPackage $package): int
    {
        $base = $this->option('base-url') ?: (config('app.url') ?: 'http://remoment.local');
        $yaml = $package->yaml($base);

        if ($path = $this->option('output')) {
            file_put_contents($path, $yaml);
            $this->components->info('Wrote '.$path);
        } else {
            $this->getOutput()->write($yaml);
        }

        return self::SUCCESS;
    }
}
