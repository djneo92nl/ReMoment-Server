<?php

namespace App\Console\Commands;

use App\Domain\Health\Soak\QueueSoak as Soak;
use Illuminate\Console\Command;

class QueueSoak extends Command
{
    protected $signature = 'queue:soak {action : enqueue | sample | report}
        {--per-queue=20 : enqueue: no-op jobs per queue}
        {--input= : report: the JSON-lines file of samples}
        {--output= : report: where to write report.md (default: print it)}';

    protected $description = 'Helper of scripts/queue-soak/run.sh: queue a mixed load of no-op jobs, sample queue health and worker memory, write the report (docs/architecture/queue-management.md)';

    public function handle(): int
    {
        switch ($this->argument('action')) {
            case 'enqueue':
                $queued = Soak::enqueue((int) $this->option('per-queue'));
                $this->line(json_encode($queued));

                return self::SUCCESS;
            case 'sample':
                $this->line(json_encode(Soak::sample()));

                return self::SUCCESS;
            case 'report':
                $file = (string) $this->option('input');
                if ($file === '' || !is_readable($file)) {
                    $this->error('--input must be the samples file.');

                    return self::FAILURE;
                }
                $samples = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));
                $report = Soak::report($samples);
                $this->option('output') ? file_put_contents($this->option('output'), $report) : $this->line($report);

                return self::SUCCESS;
        }

        $this->error('Action is enqueue, sample or report.');

        return self::FAILURE;
    }
}
