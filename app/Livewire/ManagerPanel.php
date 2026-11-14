<?php

namespace App\Livewire;

use App\Domain\Manager\ManagerClient;
use App\Domain\Manager\ManagerException;
use Livewire\Component;

/**
 * Settings › Manager: the containers of this install, the host's figures, logs, restart and the manager's audit log,
 * all through the ReMoment Manager (docs/architecture/manager.md). Admin only (the route is behind the login).
 * Upgrade and rollback run in the manager in the background; the card follows them (every 2 s while one runs).
 * The Shares card (SMB: library, exports, inbox, one share user) is the manager's `smb.js`; the generated password is shown once.
 * Restarting `app` or everything stops this page's own server, so the answer may never arrive: the page says so.
 */
class ManagerPanel extends Component
{
    public ?string $logService = null;

    public int $logTail = 200;

    public ?string $notice = null;

    public ?string $failure = null;

    /** The share user's password, only between creating it and the admin pressing "I have noted it". Never stored. */
    public ?string $sharePassword = null;

    /** The Samba fragment shown by "Preview", rendered by the manager; nothing is written. */
    public ?string $smbPreview = null;

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

    public function smbShare(string $share, bool $enabled, ManagerClient $manager, ?bool $writable = null): void
    {
        $this->smbPreview = null;
        $this->change(fn () => $manager->smbShare($share, $enabled, $writable), "The {$share} share is ".($enabled ? 'on' : 'off').'.');
    }

    /** Opens a switched-on share to guests (no password) or closes it again. Guests are opt-in per share. */
    public function smbGuest(string $share, bool $guest, ManagerClient $manager): void
    {
        $this->smbPreview = null;
        $this->change(fn () => $manager->smbShare($share, true, guest: $guest), $guest ? "The {$share} share is now open to guests." : "The {$share} share needs the password again.");
    }

    public function smbPreviewShare(string $share, bool $enabled, ManagerClient $manager, ?bool $writable = null): void
    {
        $this->notice = $this->failure = null;

        try {
            $this->smbPreview = (string) ($manager->smbShare($share, $enabled, $writable, dryRun: true)['config'] ?? '');
        } catch (ManagerException $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function closeSmbPreview(): void
    {
        $this->smbPreview = null;
    }

    public function createShareUser(ManagerClient $manager): void
    {
        $this->notice = $this->failure = $this->sharePassword = null;

        try {
            $answer = $manager->smbCreateUser();
            $this->sharePassword = $answer['password'] ?? null;
            $this->notice = ($answer['created'] ?? false) ? 'The share user was created.' : 'The share user has a new password.';
        } catch (ManagerException $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function removeShareUser(ManagerClient $manager): void
    {
        $this->sharePassword = null;
        $this->change(fn () => $manager->smbRemoveUser(), 'The share user was removed.');
    }

    public function dismissSharePassword(): void
    {
        $this->sharePassword = null;
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
        $smb = null;

        try {
            $status = $manager->status();
            $audit = $manager->audit(15);

            try {
                $job = $manager->upgradeStatus();
            } catch (ManagerException) {
                // An older manager without upgrade support: the panel simply has no upgrade card.
            }

            try {
                $smb = $manager->smb();
            } catch (ManagerException) {
                // A manager without SMB support: no shares card.
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
            'smb' => $smb,
            'error' => $error,
            'health' => $status === null ? $manager->health() : null,
        ]);
    }
}
