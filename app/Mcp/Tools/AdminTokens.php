<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). The app sign-ins of Settings › Admin & Apps (docs/api/admin-tokens.md). action: list (id, name, last used, IP; never a hash), revoke (id; confirm=true; that app has to sign in again), revoke_all (confirm=true; signs every app out, also the caller unless keep_current=true). A token is not an account. Goes through the admin REST API.')]
class AdminTokens extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'revoke', 'revoke_all'])->required(),
            'id' => $schema->integer()->description('revoke'),
            'keep_current' => $schema->boolean()->description('revoke_all: spare the token making this call'),
            'confirm' => $schema->boolean()->description('revoke, revoke_all: must be true'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:list,revoke,revoke_all', 'id' => 'nullable|integer', 'keep_current' => 'nullable|boolean', 'confirm' => 'nullable|boolean',
        ]);

        if ($data['action'] === 'list') {
            return $this->api('GET', 'admin/tokens');
        }
        if (!($data['confirm'] ?? false)) {
            return Response::error('confirmation_required (409): this signs apps out; call again with confirm=true.');
        }
        if ($data['action'] === 'revoke') {
            return isset($data['id']) ? $this->api('DELETE', 'admin/tokens/'.$data['id']) : Response::error('invalid (422): revoke needs id.');
        }

        return $this->api('DELETE', 'admin/tokens', $this->filled(['keep_current' => $data['keep_current'] ?? null]));
    }
}
