<?php

namespace App\Domain\Library;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The library maintenance actions behind `POST /api/admin/library/maintenance/{action}` and the MCP tool
 * `library_maintenance`: each is the artisan command of the same name, queued where it is long.
 */
class LibraryMaintenance
{
    public const ACTIONS = ['scan_dlna', 'verify_files', 'enrich', 'merge_duplicates', 'sync_genres', 'refresh_smart_playlists'];

    /** Actions that need `dry_run` or `confirm`: they cannot be undone. */
    public const DESTRUCTIVE = ['merge_duplicates'];

    /**
     * @param  array{dry_run?: bool, limit?: int, only?: string, server?: string, root?: string, playlist_id?: int}  $options
     * @return array{status: string, command: string, output?: string}
     */
    public function run(string $action, array $options = []): array
    {
        $dry = (bool) ($options['dry_run'] ?? false);

        return match ($action) {
            'scan_dlna' => $this->queue('library:scan', array_filter(['--server' => $options['server'] ?? null, '--root' => $options['root'] ?? null])),
            'verify_files' => $this->queue('library:verify-files'),
            'merge_duplicates' => $this->call('library:merge-duplicates', $dry ? ['--dry-run' => true] : ['--force' => true]),
            'enrich' => $this->call('library:enrich', array_filter([
                '--limit' => $options['limit'] ?? 200,
                '--dry-run' => $dry,
                '--only' => isset($options['only']) ? [$options['only']] : null,
            ], fn ($v) => $v !== false && $v !== null)),
            'sync_genres' => $this->call('library:sync-genres'),
            'refresh_smart_playlists' => $this->call('playlists:refresh-smart', isset($options['playlist_id']) ? ['--playlist' => $options['playlist_id']] : []),
        };
    }

    private function queue(string $command, array $options = []): array
    {
        Artisan::queue($command, $options);

        return ['status' => 'queued', 'command' => $command];
    }

    private function call(string $command, array $options = []): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call($command, $options, $output);

        return ['status' => $exit === 0 ? 'ok' : 'failed', 'command' => $command, 'output' => trim($output->fetch())];
    }
}
