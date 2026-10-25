<?php

namespace App\Integrations\Cast;

use App\Integrations\Cast\Console\ProbeCast;
use Illuminate\Support\ServiceProvider;

class CastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ProbeCast::class]);
        }
    }
}
