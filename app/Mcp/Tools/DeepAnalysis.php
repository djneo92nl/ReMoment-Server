<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Deep analysis of ONE track on request (docs/api/deep-analysis.md): request (track_id, kinds, default ["sections"]) queues what is not stored yet and answers status queued | already_done | not_available with a time estimate; status (track_id) says per kind none | queued | running | done | failed; remove (track_id, kind) deletes a stored result. Kind `sections` finds where the music changes (the structure service). The result shows in the track details (`sections`). Goes through the admin REST API, so its answers and errors are the same.')]
class DeepAnalysis extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['request', 'status', 'remove'])->required(),
            'track_id' => $schema->integer()->description('Library track id')->required(),
            'kinds' => $schema->array()->items($schema->string())->description('request: kinds to compute, default ["sections"]'),
            'kind' => $schema->string()->description('remove: the kind to delete, default "sections"'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:request,status,remove',
            'track_id' => 'required|integer', 'kinds' => 'nullable|array', 'kinds.*' => 'string', 'kind' => 'nullable|string',
        ]);
        $id = $data['track_id'];

        return match ($data['action']) {
            'request' => $this->api('POST', "library/tracks/{$id}/deep-analysis", ['kinds' => $data['kinds'] ?? ['sections']]),
            'status' => $this->api('GET', "library/tracks/{$id}/deep-analysis"),
            'remove' => $this->api('DELETE', 'library/tracks/'.$id.'/deep-analysis/'.($data['kind'] ?? 'sections')),
        };
    }
}
