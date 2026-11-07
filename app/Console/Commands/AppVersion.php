<?php

namespace App\Console\Commands;

use App\Domain\Profile\Profile;
use App\Domain\System\InstalledVersion;
use Illuminate\Console\Command;

class AppVersion extends Command
{
    protected $signature = 'app:version
        {--json : Print version, recorded version and profile as JSON}
        {--record : Record the running version in the database as the installed one}';

    protected $description = 'Print the application version (the VERSION file); --record stores it as the installed version';

    public function handle(): int
    {
        $recordedNow = $this->option('record') ? InstalledVersion::record() : null;

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'version' => InstalledVersion::current(),
                'recorded' => InstalledVersion::recorded(),
                'profile' => Profile::current(),
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(InstalledVersion::current());

        if ($recordedNow !== null) {
            $this->info("Recorded installed version {$recordedNow}.");
        }

        return self::SUCCESS;
    }
}
