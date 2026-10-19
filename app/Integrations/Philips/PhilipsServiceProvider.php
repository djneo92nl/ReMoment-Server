<?php

namespace App\Integrations\Philips;

use App\Integrations\Philips\Console\PairPhilipsTv;
use Illuminate\Support\ServiceProvider;

class PhilipsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PairPhilipsTv::class]);
        }
    }
}
