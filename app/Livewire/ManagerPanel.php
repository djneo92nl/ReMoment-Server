<?php

namespace App\Livewire;

use App\Domain\Manager\ManagerClient;
use App\Domain\Manager\ManagerException;
use App\Domain\Profile\Profile;
use App\Models\StorageLocation;
use Livewire\Component;

/**
 * Settings › Manager: the containers of this install, the host's figures, logs, restart and the manager's audit log,
 * all through the ReMoment Manager (docs/architecture/manager.md). Admin only (the route is behind the login).
 * Upgrade and rollback run in the manager in the background; the card follows them (every 2 s while one runs).
 * The Shares card (SMB: library, exports, inbox, one share user) is the manager's `smb.js`; the generated password is shown once.
 * The NAS mounts card is the manager's `mount.js`: a form, a preview (the manager's dry run: unit files, fstab line, plan token),
 * and Apply, which sends the plan token of that preview back. The NAS password is typed here, goes to the manager once and is cleared.
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

    // --- NAS mount form (the manager's mount.js). The password only lives here between typing it and Apply/Cancel. ---
    public bool $mountForm = false;

    public string $mountName = '';

    public string $mountHost = '';

    public string $mountShare = '';

    public string $mountUser = '';

    public string $mountDomain = '';

    public string $mountPassword = '';

    public bool $mountWritable = false;

    public string $mountVersion = 'default';

    /** The manager's dry run of the form: unit files, fstab line, credentials (password hidden), actions and the plan token. */
    public ?array $mountPlan = null;

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

    /** @return array<string, mixed> the form as the manager's API takes it (empty optional fields left out) */
    private function mountFormData(): array
    {
        return array_filter([
            'name' => trim($this->mountName),
            'host' => trim($this->mountHost),
            'share' => trim($this->mountShare, " \t/"),
            'user' => trim($this->mountUser) ?: null,
            'domain' => trim($this->mountDomain) ?: null,
            'password' => $this->mountPassword !== '' ? $this->mountPassword : null,
            'version' => $this->mountVersion,
        ], fn ($v) => $v !== null) + ['writable' => $this->mountWritable];
    }

    public function newMount(): void
    {
        $this->resetMountForm();
        $this->mountForm = true;
    }

    public function cancelMount(): void
    {
        $this->resetMountForm();
    }

    private function resetMountForm(): void
    {
        $this->reset(['mountForm', 'mountName', 'mountHost', 'mountShare', 'mountUser', 'mountDomain', 'mountPassword', 'mountWritable', 'mountVersion', 'mountPlan']);
        $this->resetErrorBag();
    }

    /** Step one: ask the manager what it would do. Writes nothing on the host. */
    public function previewMount(ManagerClient $manager): void
    {
        $this->notice = $this->failure = null;
        $this->mountPlan = null;

        try {
            $this->mountPlan = $manager->mountPreview($this->mountFormData());
        } catch (ManagerException $e) {
            $this->failure = $e->getMessage();
        }
    }

    /** Step two, only after a preview: apply with that preview's plan token. A changed form needs a new preview. */
    public function applyMount(ManagerClient $manager): void
    {
        $this->notice = $this->failure = null;

        if ($this->mountPlan === null) {
            $this->failure = 'Preview what will be done first.';

            return;
        }

        try {
            $answer = $manager->mountAdd($this->mountFormData(), (string) $this->mountPlan['plan']);
            $this->notice = ($answer['updated'] ?? false)
                ? "The mount {$this->mountName} was updated."
                : "The mount {$this->mountName} was added at ".($answer['path'] ?? '').'. It is mounted when it is first used.';
            $this->resetMountForm();
        } catch (ManagerException $e) {
            $this->failure = $e->getMessage();
            $this->mountPlan = null; // the plan may be stale: preview again
        } finally {
            $this->mountPassword = ''; // the password never stays in the component
        }
    }

    public function mountActive(string $name, bool $active, ManagerClient $manager): void
    {
        $this->change(function () use ($manager, $name, $active) {
            $plan = $manager->mountActive($name, $active)['plan'];
            $manager->mountActive($name, $active, $plan);
        }, $active ? "The mount {$name} is on." : "The mount {$name} is off and unmounted.");
    }

    /** Removes the mount unless a storage location still lives on it. */
    public function removeMount(string $name, ManagerClient $manager): void
    {
        $this->notice = $this->failure = null;

        try {
            $mount = collect($manager->mounts()['data'] ?? [])->firstWhere('name', $name);
            $using = $mount ? $this->locationsOn($mount['path']) : [];

            if ($using !== []) {
                $this->failure = 'The storage location '.implode(', ', $using).' uses this mount. Change or remove it in Settings › Storage first.';

                return;
            }

            $plan = $manager->mountRemove($name)['plan'];
            $manager->mountRemove($name, $plan);
            $this->notice = "The mount {$name} was removed.";
        } catch (ManagerException $e) {
            $this->failure = $e->getMessage();
        }
    }

    /**
     * Registers the mount's folder as a storage location (the entry only: Settings › Storage does the rest). The path comes
     * from the manager's own answer, never from the browser, and the folder has to be mounted and readable right now.
     */
    public function useMountAsStorage(string $name, string $role, ManagerClient $manager): void
    {
        $this->notice = $this->failure = null;

        if (!Profile::allows('file_storage')) {
            $this->failure = 'This profile has no own files, so there are no storage locations.';

            return;
        }

        if (!in_array($role, [StorageLocation::ROLE_LIBRARY, StorageLocation::ROLE_INBOX], true)) {
            return;
        }

        try {
            $mount = collect($manager->mounts()['data'] ?? [])->firstWhere('name', $name);
        } catch (ManagerException $e) {
            $this->failure = $e->getMessage();

            return;
        }

        if (!$mount) {
            $this->failure = 'That mount does not exist (any more).';

            return;
        }

        if (($mount['state'] ?? '') !== 'ok') {
            $this->failure = 'The share is not mounted and readable right now, so it cannot be used as a storage location yet.';

            return;
        }

        if ($role === StorageLocation::ROLE_INBOX && !($mount['writable'] ?? false)) {
            $this->failure = 'An inbox needs a writable mount: the files are moved out of it.';

            return;
        }

        $path = rtrim((string) $mount['path'], '/');

        if ($this->locationsOn($path, exact: true) !== []) {
            $this->failure = 'A storage location for this folder exists already.';

            return;
        }

        $label = 'NAS '.$name;
        $unique = $label;
        for ($i = 2; StorageLocation::where('name', $unique)->exists(); $i++) {
            $unique = "{$label} {$i}";
        }

        StorageLocation::create(['name' => $unique, 'driver' => 'local', 'role' => $role, 'config' => ['root' => $path]]);
        $this->notice = "Added the storage location {$unique} ({$role}). Scan it in Settings › Storage.";
    }

    /** @return list<string> names of the local storage locations whose root is this folder (exact) or inside it */
    private function locationsOn(string $path, bool $exact = false): array
    {
        $path = rtrim($path, '/');

        return StorageLocation::all()
            ->filter(function (StorageLocation $l) use ($path, $exact) {
                $root = rtrim((string) ($l->config['root'] ?? ''), '/');

                return $l->isLocal() && $root !== '' && ($root === $path || (!$exact && str_starts_with($root, $path.'/')));
            })
            ->pluck('name')->all();
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
        $mounts = null;

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

            try {
                $mounts = $manager->mounts();
            } catch (ManagerException) {
                // A manager without NAS mount support: no mounts card.
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
            'mounts' => $mounts,
            'canUseAsStorage' => Profile::allows('file_storage'),
            'error' => $error,
            'health' => $status === null ? $manager->health() : null,
        ]);
    }
}
