<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Client devices and nodes that registered themselves (docs/api/admin-clients.md). action: list (pending first; optional status pending|approved), get (id; includes the API token), approve (id; optional type single|multi, name, roles = a node\'s declared roles to switch on), reject (id; a pending registration), update (id; any of name, type, device_ids, roles, hidden_sources), regenerate_token (id; the old token stops working), delete (id; confirm=true). Goes through the admin REST API.')]
class Clients extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'approve', 'reject', 'update', 'regenerate_token', 'delete'])->required(),
            'id' => $schema->integer()->description('Every action except list'),
            'status' => $schema->string()->enum(['pending', 'approved'])->description('list'),
            'name' => $schema->string()->description('approve, update'),
            'type' => $schema->string()->enum(['single', 'multi'])->description('approve, update: single keeps at most one device'),
            'device_ids' => $schema->array()->items($schema->integer())->description('update: the devices the client may use (replaces the list)'),
            'roles' => $schema->array()->items($schema->string())->description('approve, update: the roles of a node switched on (within what it declared)'),
            'hidden_sources' => $schema->array()->items($schema->string())->description('update: library ids the client does not see (replaces the list)'),
            'confirm' => $schema->boolean()->description('delete: must be true'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:list,get,approve,reject,update,regenerate_token,delete', 'id' => 'nullable|integer', 'status' => 'nullable|in:pending,approved',
            'name' => 'nullable|string', 'type' => 'nullable|in:single,multi', 'device_ids' => 'nullable|array', 'device_ids.*' => 'integer',
            'roles' => 'nullable|array', 'roles.*' => 'string', 'hidden_sources' => 'nullable|array', 'hidden_sources.*' => 'string', 'confirm' => 'nullable|boolean',
        ]);

        if ($data['action'] !== 'list' && !isset($data['id'])) {
            return Response::error("invalid (422): {$data['action']} needs id.");
        }
        if ($data['action'] === 'delete' && !($data['confirm'] ?? false)) {
            return Response::error('confirmation_required (409): deleting a client signs that device out for good; call again with confirm=true.');
        }

        $path = 'admin/clients/'.($data['id'] ?? '');
        $fields = fn (array $keys) => $this->filled(array_intersect_key($data, array_flip($keys)));

        return match ($data['action']) {
            'list' => $this->api('GET', 'admin/clients', $fields(['status'])),
            'get' => $this->api('GET', $path),
            'approve' => $this->api('POST', "$path/approve", $fields(['type', 'name', 'roles'])),
            'reject' => $this->api('POST', "$path/reject"),
            'update' => $this->api('PUT', $path, $fields(['name', 'type', 'device_ids', 'roles', 'hidden_sources'])),
            'regenerate_token' => $this->api('POST', "$path/regenerate-token"),
            default => $this->api('DELETE', $path),
        };
    }
}
