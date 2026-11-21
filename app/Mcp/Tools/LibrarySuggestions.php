<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Act on a tag or cover suggestion from library_health report `suggestions` (docs/api/suggestions.md). action: accept (writes the suggested tag into the file, which rewrites the user\'s file, or queues the fetch of the album\'s front cover from the Cover Art Archive; needs confirm=true) or dismiss (hides that suggested value). `id` is the finding\'s suggestion.id, e.g. title:412 or cover:7. Never accept without the user\'s agreement.')]
class LibrarySuggestions extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['accept', 'dismiss'])->required(),
            'id' => $schema->string()->description('suggestion.id of a finding, e.g. title:412, artist:412, year:412, cover:7')->required(),
            'confirm' => $schema->boolean()->description('accept: required, because a tag suggestion rewrites the file'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate(['action' => 'required|in:accept,dismiss', 'id' => 'required|string|max:100', 'confirm' => 'nullable|boolean']);

        if ($data['action'] === 'accept' && empty($data['confirm'])) {
            return Response::error('confirmation_required (422): accepting rewrites the tags of a file or replaces a cover; ask the user, then pass confirm=true.');
        }

        return $this->api('POST', 'admin/suggestions/'.rawurlencode($data['id']).'/'.$data['action']);
    }
}
