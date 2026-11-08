<?php

namespace App\Domain\System;

use App\Domain\Alerts\Alerts;
use App\Domain\Health\Heartbeat;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The health check after an upgrade, from the data /settings/health reads: the database answers, no critical alert
 * is active, and the scheduler and queue heartbeats show life. Polls until everything is fine or the timeout passes.
 */
final class UpgradeHealth
{
    private const STALE_SECONDS = 180;

    /** @return array{ok: bool, failures: list<string>} */
    public static function wait(int $timeoutSeconds, int $intervalSeconds = 5): array
    {
        $start = microtime(true);
        do {
            $failures = self::failures();
            if ($failures === [] || microtime(true) - $start >= $timeoutSeconds) {
                break;
            }
            usleep(max(1, $intervalSeconds) * 1_000_000);
        } while (true);

        return ['ok' => $failures === [], 'failures' => $failures];
    }

    /** @return list<string> */
    public static function failures(): array
    {
        $failures = [];
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            return ['The database does not answer: '.$e->getMessage()];
        }

        try {
            foreach (Alerts::active() as $alert) {
                if ($alert->severity === 'critical') {
                    $failures[] = "Critical alert: {$alert->title}. {$alert->message}";
                }
            }
        } catch (Throwable $e) {
            $failures[] = 'Cannot read the alerts: '.$e->getMessage();
        }

        foreach ([Heartbeat::SCHEDULER => 'scheduler', Heartbeat::QUEUE => 'queue worker'] as $key => $label) {
            try {
                $last = Heartbeat::last($key);
            } catch (Throwable) {
                $last = null;
            }
            if ($last === null || $last->diffInSeconds(now(), true) >= self::STALE_SECONDS) {
                $failures[] = "No recent heartbeat from the {$label}: it is not running.";
            }
        }

        return $failures;
    }
}
