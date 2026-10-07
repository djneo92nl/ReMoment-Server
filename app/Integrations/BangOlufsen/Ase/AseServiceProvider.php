<?php

namespace App\Integrations\BangOlufsen\Ase;

use App\Integrations\BangOlufsen\Ase\Console\AseMap;
use Illuminate\Support\ServiceProvider;

class AseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AseMap::class]);
        }
    }
}
