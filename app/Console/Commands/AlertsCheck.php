<?php

namespace App\Console\Commands;

use App\Domain\Alerts\AlertChecks;
use Illuminate\Console\Command;

class AlertsCheck extends Command
{
    protected $signature = 'alerts:check';

    protected $description = 'Raise and resolve the alerts for the health conditions (scheduler, queues, backup, storage, devices)';

    public function handle(AlertChecks $checks): int
    {
        $checks->runAll();

        return self::SUCCESS;
    }
}
