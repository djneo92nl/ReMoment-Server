<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Own music files (docs/architecture/file-storage.md): locations (list storage locations), test_location / scan_location (id; scan is queued), import (inbox → library), reorganize (dry_run first; confirm=true to move files), track_files (track_id), verify_file (file_id). Goes through the admin REST API, so its answers and errors are the same.')]
class Storage extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['locations', 'test_location', 'scan_location', 'import', 'reorganize', 'track_files', 'verify_file'])->required(),
            'id' => $schema->integer()->description('Location id (test_location, scan_location), track id (track_files) or file id (verify_file)'),
            'dry_run' => $schema->boolean()->description('reorganize: only list the moves'),
            'confirm' => $schema->boolean()->description('reorganize: required when not a dry run'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:locations,test_location,scan_location,import,reorganize,track_files,verify_file',
            'id' => 'nullable|integer', 'dry_run' => 'nullable|boolean', 'confirm' => 'nullable|boolean',
        ]);

        if (in_array($data['action'], ['test_location', 'scan_location', 'track_files', 'verify_file'], true) && !isset($data['id'])) {
            return Response::error('invalid (422): this action needs an id.');
        }

        return match ($data['action']) {
            'locations' => $this->api('GET', 'admin/storage/locations'),
            'test_location' => $this->api('POST', "admin/storage/locations/{$data['id']}/test"),
            'scan_location' => $this->api('POST', "admin/storage/locations/{$data['id']}/scan", ['queue' => true]),
            'import' => $this->api('POST', 'admin/storage/import'),
            'reorganize' => !empty($data['dry_run']) || !empty($data['confirm'])
                ? $this->api('POST', 'admin/storage/reorganize', ['dry_run' => !empty($data['dry_run'])])
                : Response::error('confirmation_required (422): reorganize moves files; run with dry_run=true first, then confirm=true.'),
            'track_files' => $this->api('GET', "admin/tracks/{$data['id']}/files"),
            'verify_file' => $this->api('POST', "admin/files/{$data['id']}/verify"),
        };
    }
}
