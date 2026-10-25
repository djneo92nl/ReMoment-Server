<?php

namespace App\Integrations\Cast\Console;

use App\Integrations\Cast\CastConnection;
use Illuminate\Console\Command;
use Throwable;

class ProbeCast extends Command
{
    protected $signature = 'cast:probe {host : IP of the Cast receiver (Chromecast or a TV with Chromecast built in)} {--port=8009}';

    protected $description = 'Connect to a Cast receiver and print its receiver status (read-only: connect and GET_STATUS, nothing is launched or played)';

    public function handle(): int
    {
        $connection = new CastConnection($this->argument('host'), (int) $this->option('port'));

        try {
            $connection->connect();
            $status = $connection->receiverStatus();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $connection->close();
        }

        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
