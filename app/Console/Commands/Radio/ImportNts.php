<?php

namespace App\Console\Commands\Radio;

use App\Domain\Radio\RadioSettings;
use App\Integrations\Radio\Nts\NtsImporter;
use Illuminate\Console\Command;

class ImportNts extends Command
{
    protected $signature = 'radio:import-nts';

    protected $description = 'Add NTS Radio (live channels 1 and 2, all mixtapes) as radio stations; run again to refresh the streams';

    public function handle(NtsImporter $importer): int
    {
        if (!RadioSettings::ntsEnabled()) {
            $this->warn('NTS is off: enable it under Settings › Files & Import › Library (read the note there first).');

            return self::FAILURE;
        }

        $result = $importer->import();
        $this->info("NTS: {$result['created']} added, {$result['updated']} refreshed.");

        return self::SUCCESS;
    }
}
