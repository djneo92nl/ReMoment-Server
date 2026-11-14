<?php

namespace App\Console\Commands;

use App\Domain\Push\Vapid;
use Illuminate\Console\Command;

class PushVapidCommand extends Command
{
    protected $signature = 'push:vapid {--rotate : Make a new key pair; every stored subscription is deleted, browsers must turn push on again} {--force : Do not ask}';

    protected $description = 'Create the Web Push (VAPID) key pair once, or show its public key. The private key is never printed.';

    public function handle(): int
    {
        if ($this->option('rotate')) {
            if (!$this->option('force') && !$this->confirm('A new key pair invalidates every browser that turned push on. Continue?')) {
                return self::FAILURE;
            }
            Vapid::replace();
            $this->components->info('New key pair made; stored subscriptions deleted.');
        } elseif (Vapid::ensure()) {
            $this->components->info('Key pair created.');
        }

        $this->line('Public key: '.Vapid::publicKey());

        return self::SUCCESS;
    }
}
