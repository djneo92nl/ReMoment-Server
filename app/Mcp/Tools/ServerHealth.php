<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Health of this server, the data of /settings/health (docs/api/admin-health.md). action: summary (MQTT broker, scheduler and queue worker heartbeats, queue size and failed jobs, last backup, last upgrade, radio relay, active alerts, every device with its listener), services (the cached probes of the external services: Spotify, Last.fm, MusicBrainz, the analysis sidecars ...), check_services (run the probes now, all or the ids in `only`; each takes up to 8 s). backup_copy (the optional second copy of every backup: its folder and the last copy), set_backup_copy_path (`path` of a mounted share or drive, tested first; empty switches the copy off), test_backup_copy (write test in `path` or the saved one). Alerts have their own tool (`alerts`). Goes through the admin REST API.')]
class ServerHealth extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['summary', 'services', 'check_services', 'backup_copy', 'set_backup_copy_path', 'test_backup_copy'])->required(),
            'only' => $schema->array()->items($schema->string())->description('check_services: only these probe ids (see the ids in `services`)'),
            'path' => $schema->string()->description('set_backup_copy_path: the folder (empty = no copy); test_backup_copy: the folder to test (default: the saved one)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate(['action' => 'required|in:summary,services,check_services,backup_copy,set_backup_copy_path,test_backup_copy', 'only' => 'nullable|array', 'only.*' => 'string', 'path' => 'nullable|string']);

        return match ($data['action']) {
            'services' => $this->api('GET', 'admin/health/services'),
            'check_services' => $this->api('POST', 'admin/health/services/check', $this->filled(['only' => $data['only'] ?? null])),
            'backup_copy' => $this->api('GET', 'admin/backup/copy-path'),
            'set_backup_copy_path' => $this->api('PUT', 'admin/backup/copy-path', ['path' => $data['path'] ?? null]),
            'test_backup_copy' => $this->api('POST', 'admin/backup/copy-path/test', $this->filled(['path' => $data['path'] ?? null])),
            default => $this->api('GET', 'admin/health'),
        };
    }
}
