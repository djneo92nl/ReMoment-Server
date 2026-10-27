<?php

namespace App\Console\Commands\Backup;

use App\Domain\Backup\BackupStatus;
use App\Domain\Backup\BackupWriter;
use Illuminate\Console\Command;
use Throwable;

class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--path= : Directory for the archive (default: config backup.path, BACKUP_PATH)}
        {--keep= : Archives to keep afterwards (default: config backup.keep, BACKUP_KEEP)}
        {--no-db : Leave the database out (manifest and safe settings only)}
        {--with-secrets : ALSO write credentials (admin login, OAuth tokens, client tokens) into the archive}';

    protected $description = 'Write a timestamped backup archive (database, safe settings, manifest) and prune old ones';

    public function handle(BackupWriter $writer): int
    {
        $dir = (string) ($this->option('path') ?: config('backup.path'));
        $keep = max(1, (int) ($this->option('keep') ?? config('backup.keep')));
        $secrets = (bool) $this->option('with-secrets');

        if ($secrets) {
            $this->warn('WARNING: this archive will contain credentials: the admin password hash, Spotify and Last.fm tokens and secrets, client API tokens.');
            $this->warn('Anyone who can read it can use them. Keep it off shared storage; it is not encrypted.');
        }

        try {
            $file = $writer->write($dir, !$this->option('no-db'), $secrets);
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Backup written: %s (%s)', $file, $this->size(filesize($file))));

        foreach (array_slice(BackupStatus::archives($dir), 0, -$keep) as $old) {
            if (@unlink($old)) {
                $this->line('Removed old backup '.basename($old));
            }
        }

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
