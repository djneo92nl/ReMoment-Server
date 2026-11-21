<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Library maintenance, same as the artisan commands. scan_dlna (queued; optional server ip, root folder), enrich (queues metadata/artist-image lookups; limit, dry_run), merge_duplicates (dry_run lists what would merge; for real needs confirm=true; merged ids disappear so clients get 404 on them), sync_genres, refresh_smart_playlists (optional playlist_id), verify_files (queued). Storage scan/import/reorganize: use the storage tool. Goes through POST /api/admin/library/maintenance/{action} (docs/api/admin-library.md).')]
class LibraryMaintenance extends Tool
{
    use CallsApi;
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

        return $this->api('POST', 'admin/library/maintenance/'.$data['action'], $this->filled(array_diff_key($data, ['action' => 1])));
    }
}
