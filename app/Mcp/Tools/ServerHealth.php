<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Health of this server, the data of /settings/health (docs/api/admin-health.md). action: summary (MQTT broker, scheduler and queue worker heartbeats, queue size and failed jobs, last backup, last upgrade, radio relay, active alerts, every device with its listener), services (the cached probes of the external services: Spotify, Last.fm, MusicBrainz, the analysis sidecars ...), check_services (run the probes now, all or the ids in `only`; each takes up to 8 s). Alerts have their own tool (`alerts`). Goes through the admin REST API.')]
class ServerHealth extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['summary', 'services', 'check_services'])->required(),
            'only' => $schema->array()->items($schema->string())->description('check_services: only these probe ids (see the ids in `services`)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate(['action' => 'required|in:summary,services,check_services', 'only' => 'nullable|array', 'only.*' => 'string']);

        return match ($data['action']) {
            'services' => $this->api('GET', 'admin/health/services'),
            'check_services' => $this->api('POST', 'admin/health/services/check', $this->filled(['only' => $data['only'] ?? null])),
            default => $this->api('GET', 'admin/health'),
        };
    }
}
