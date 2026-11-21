<?php

namespace App\Livewire;

use App\Domain\Backup\BackupCopy;
use Livewire\Component;

/**
 * The optional second copy of every backup, on /settings/health (System tab): one directory (a mounted NAS share or
 * USB drive), a test-write button and the result of the last copy. Admin only; the path never reaches a guest.
 * Same rules as PUT /api/admin/backup/copy-path (App\Domain\Backup\BackupCopy, docs/api/admin-backup.md).
 */
class BackupCopyCard extends Component
{
    public string $path = '';

    /** Result of the last click: [ok, message]. */
    public ?array $result = null;

    public function mount(): void
    {
        $this->path = (string) BackupCopy::path();
    }

    public function test(): void
    {
        abort_unless(auth()->check(), 403);

        $path = trim($this->path);
        $this->result = $path === ''
            ? ['ok' => false, 'message' => 'Enter a directory first.']
            : BackupCopy::usable($path);
    }

    /** Saves after a test-write; an empty field switches the copy off. */
    public function save(): void
    {
        abort_unless(auth()->check(), 403);

        $path = trim($this->path);
        if ($path === '') {
            BackupCopy::setPath(null);
            $this->result = ['ok' => true, 'message' => 'No copy: backups stay in the backup directory only.'];

            return;
        }

        $check = BackupCopy::usable($path);
        if ($check['ok']) {
            BackupCopy::setPath($path);
            $this->path = (string) BackupCopy::path();
            $check['message'] = 'Saved. '.$check['message'].' Every new backup is copied there.';
        }
        $this->result = $check;
    }

    public function render()
    {
        return view('livewire.backup-copy-card', [
            'saved' => BackupCopy::path(),
            'last' => BackupCopy::status(),
            'keep' => max(1, (int) config('backup.keep')),
        ]);
    }
}
