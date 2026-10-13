<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\Console\Output\BufferedOutput;

#[Description('ADMIN (needs the admin token). Library maintenance, same as the artisan commands. scan_dlna (queued; optional server ip, root folder), enrich (queues metadata/artist-image lookups; limit, dry_run), merge_duplicates (dry_run lists what would merge; for real needs confirm=true; merged ids disappear so clients get 404 on them), sync_genres, refresh_smart_playlists (optional playlist_id), verify_files (queued). Storage scan/import/reorganize: use the storage tool.')]
class LibraryMaintenance extends Tool
{
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['scan_dlna', 'enrich', 'merge_duplicates', 'sync_genres', 'refresh_smart_playlists', 'verify_files'])->required(),
            'dry_run' => $schema->boolean()->description('enrich / merge_duplicates: only report'),
            'confirm' => $schema->boolean()->description('merge_duplicates: required when not a dry run'),
            'limit' => $schema->integer()->description('enrich: max items to queue (default 200)')->min(1)->max(5000),
            'only' => $schema->string()->enum(['metadata', 'artist-images'])->description('enrich: one part only'),
            'server' => $schema->string()->description('scan_dlna: IP of one DLNA server'),
            'root' => $schema->string()->description('scan_dlna: folder to start in, e.g. Muziek/Albums'),
            'playlist_id' => $schema->integer()->description('refresh_smart_playlists: one playlist'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:scan_dlna,enrich,merge_duplicates,sync_genres,refresh_smart_playlists,verify_files',
            'dry_run' => 'nullable|boolean', 'confirm' => 'nullable|boolean', 'limit' => 'nullable|integer|min:1|max:5000',
            'only' => 'nullable|in:metadata,artist-images', 'server' => 'nullable|string', 'root' => 'nullable|string', 'playlist_id' => 'nullable|integer',
        ]);

        $dry = (bool) ($data['dry_run'] ?? false);

        switch ($data['action']) {
            case 'scan_dlna':
                Artisan::queue('library:scan', array_filter(['--server' => $data['server'] ?? null, '--root' => $data['root'] ?? null]));

                return Response::json(['status' => 'queued', 'command' => 'library:scan']);
            case 'verify_files':
                Artisan::queue('library:verify-files');

                return Response::json(['status' => 'queued', 'command' => 'library:verify-files']);
            case 'merge_duplicates':
                if (!$dry && empty($data['confirm'])) {
                    return Response::error('confirmation_required (422): merging cannot be undone; run with dry_run=true first, then confirm=true.');
                }

                return $this->run('library:merge-duplicates', $dry ? ['--dry-run' => true] : ['--force' => true]);
            case 'enrich':
                $options = ['--limit' => $data['limit'] ?? 200, '--dry-run' => $dry];
                if (isset($data['only'])) {
                    $options['--only'] = [$data['only']];
                }

                return $this->run('library:enrich', array_filter($options, fn ($v) => $v !== false));
            case 'sync_genres':
                return $this->run('library:sync-genres');
            default:
                return $this->run('playlists:refresh-smart', isset($data['playlist_id']) ? ['--playlist' => $data['playlist_id']] : []);
        }
    }

    private function run(string $command, array $options = []): Response
    {
        $output = new BufferedOutput;
        $exit = Artisan::call($command, $options, $output);

        return Response::json(['status' => $exit === 0 ? 'ok' : 'failed', 'command' => $command, 'output' => trim($output->fetch())]);
    }
}
