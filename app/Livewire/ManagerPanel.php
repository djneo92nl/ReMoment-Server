<?php

namespace App\Livewire;

use App\Domain\Manager\ManagerClient;
use App\Domain\Manager\ManagerException;
use Livewire\Component;

/**
 * Settings › Manager: the containers of this install, the host's figures, logs, restart and the manager's audit log,
 * all through the ReMoment Manager (docs/architecture/manager.md). Admin only (the route is behind the login).
 * Upgrade and rollback run in the manager in the background; the card follows them (every 2 s while one runs).
 * Restarting `app` or everything stops this page's own server, so the answer may never arrive: the page says so.
 */
class ManagerPanel extends Component
{
    public ?string $logService = null;

    public int $logTail = 200;

    public ?string $notice = null;

    public ?string $failure = null;

    public function showLogs(string $service): void
    {
        $this->logService = $this->logService === $service ? null : $service;
    }

    public function closeLogs(): void
    {
        $this->logService = null;
    }

    public function restart(string $service, ManagerClient $manager): void
    {
        $this->change(fn () => $manager->restart($service), "Restarted {$service}.");
    }

    public function restartAll(ManagerClient $manager): void
    {
        $this->change(fn () => $manager->restartAll(), 'Restarted every container.');
    }

    public function upgrade(ManagerClient $manager): void
    {
        $this->change(fn () => $manager->upgrade(), 'The upgrade started. It backs up first, then migrates and checks the app; this page follows it.');
    }

    public function rollback(ManagerClient $manager): void
    {
        $this->change(fn () => $manager->rollback(), 'The rollback started.');
    }

    private function change(callable $change, string $done): void
    {
        $this->notice = $this->failure = null;

        try {
            $change();
            $this->notice = $done;
        } catch (ManagerException $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function render(ManagerClient $manager)
    {
        $status = null;
        $audit = [];
        $logs = null;
        $error = null;
        $job = null;

        try {
            $status = $manager->status();
            $audit = $manager->audit(15);

            try {
                $job = $manager->upgradeStatus();
            } catch (ManagerException) {
                // An older manager without upgrade support: the panel simply has no upgrade card.
            }

            if ($this->logService !== null) {
                $logs = $manager->logs($this->logService, $this->logTail);
            }
        } catch (ManagerException $e) {
            $error = $e->getMessage();
        }

        return view('livewire.manager-panel', [
            'status' => $status,
            'audit' => $audit,
            'logs' => $logs,
            'job' => $job,
            'error' => $error,
            'health' => $status === null ? $manager->health() : null,
        ]);
    }
}
