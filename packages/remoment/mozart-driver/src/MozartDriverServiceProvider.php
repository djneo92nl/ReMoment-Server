<?php

namespace Remoment\MozartDriver;

use Illuminate\Support\ServiceProvider;

class MozartDriverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mozart.php', 'mozart');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/mozart.php' => config_path('mozart.php'),
        ], 'mozart-config');
    }
}
