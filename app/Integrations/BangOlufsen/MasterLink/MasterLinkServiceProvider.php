<?php

namespace App\Integrations\BangOlufsen\MasterLink;

use App\Integrations\BangOlufsen\MasterLink\Console\MlgwProbe;
use Illuminate\Support\ServiceProvider;

class MasterLinkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MlgwProbe::class]);
        }
    }
}
