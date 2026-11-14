<?php

namespace App\Console\Commands;

use App\Domain\Push\Push;
use App\Models\PushSubscription;
use Illuminate\Console\Command;

class PushTestCommand extends Command
{
    protected $signature = 'push:test {--message=Web Push works in this browser. : Text of the notification}';

    protected $description = 'Send a test notification to every browser that turned Web Push on (no alert is raised)';

    public function handle(): int
    {
        $count = PushSubscription::count();
        if ($count === 0) {
            $this->components->warn('No browser has turned push on (Settings › Health › Alert notifications).');

            return self::SUCCESS;
        }

        $result = Push::deliver([
            'id' => 'test', 'severity' => 'warning', 'title' => 'ReMoment', 'body' => (string) $this->option('message'),
            'tag' => 'remoment-push-test', 'url' => '/settings/health',
        ]);

        $this->components->info("Sent {$result['sent']} of {$count}, removed {$result['removed']} gone, {$result['failed']} failed (see the log).");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
