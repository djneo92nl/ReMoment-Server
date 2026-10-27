<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Alerts of the server (docs/architecture/alerts.md): conditions like a stuck queue, a silent scheduler, a stale backup, unreachable storage, an offline device or a down listener. `list` (state active|resolved|all, since = id, limit) is open; `ack` (id) acknowledges one and needs the admin token.')]
class Alerts extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    /** Listed for everyone: only the ack action needs the admin token. */
    public function shouldRegister(): bool
    {
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'ack'])->description('Default list')->default('list'),
            'state' => $schema->string()->enum(['active', 'resolved', 'all'])->description('list: default all'),
            'since' => $schema->integer()->description('list: only alerts with an id above this'),
            'limit' => $schema->integer()->description('list: 1-200, default 100'),
            'id' => $schema->integer()->description('ack: the alert id'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'nullable|in:list,ack', 'state' => 'nullable|in:active,resolved,all',
            'since' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:200', 'id' => 'nullable|integer',
        ]);

        if (($data['action'] ?? 'list') === 'ack') {
            if ($denied = $this->adminDenied()) {
                return $denied;
            }
            if (!isset($data['id'])) {
                return Response::error('invalid (422): ack needs an id.');
            }

            return $this->api('POST', "admin/alerts/{$data['id']}/ack");
        }

        return $this->api('GET', 'alerts', array_filter(
            ['state' => $data['state'] ?? null, 'since' => $data['since'] ?? null, 'limit' => $data['limit'] ?? null],
            fn ($v) => $v !== null,
        ));
    }
}
