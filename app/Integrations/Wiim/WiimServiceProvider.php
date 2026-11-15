<?php

namespace App\Integrations\Wiim;

use App\Integrations\Wiim\Console\WiimProbe;
use Illuminate\Support\ServiceProvider;

class WiimServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WiimProbe::class]);
        }
    }
}
