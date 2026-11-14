<?php

namespace App\Console\Commands;

use App\Domain\Hardware\HardwareTable as Table;
use Illuminate\Console\Command;

class HardwareTable extends Command
{
    protected $signature = 'hardware:table
        {--format=markdown : markdown (write into docs/user/hardware.md) or json (print)}
        {--check : Exit 1 when the committed page differs from the generated one; writes nothing}';

    protected $description = 'Generate the supported-hardware table of docs/user/hardware.md from config/devices.php and config/hardware-status.php';

    public function handle(): int
    {
        $format = $this->option('format');

        if (!in_array($format, ['markdown', 'json'], true)) {
            $this->error('--format must be markdown or json.');

            return self::INVALID;
        }

        if ($format === 'json') {
            $this->getOutput()->write(Table::json());

            return self::SUCCESS;
        }

        $path = base_path('docs/user/hardware.md');
        $page = is_file($path) ? (string) file_get_contents($path) : '';
        $new = Table::render($page);

        if ($new === null) {
            $this->error('docs/user/hardware.md lacks the '.Table::START.' and '.Table::END.' markers.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            if ($new !== $page) {
                $this->error('docs/user/hardware.md is out of date: run `php artisan hardware:table`.');

                return self::FAILURE;
            }

            $this->info('docs/user/hardware.md is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($path, $new);
        $this->info('Wrote the hardware table to docs/user/hardware.md.');

        return self::SUCCESS;
    }
}
