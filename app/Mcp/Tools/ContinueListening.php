<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Resume positions of audiobooks, long tracks and podcast episodes (docs/api/playback-positions.md). `list` shows what can be continued, most recent first (kind track or episode, id, position and duration in seconds, percent). `set` (kind, id, position) keeps a position by hand; `clear` (kind, id) forgets it. Playing a bookmarked track (play_from_library) or episode (podcasts, action play) on a device that can seek continues at its position; pass resume=false there to start from the beginning. The position is kept from the progress the device reports and cleared when the item finishes.')]
class ContinueListening extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'set', 'clear'])->required(),
            'kind' => $schema->string()->enum(['track', 'episode'])->description('set/clear'),
            'id' => $schema->integer()->description('set/clear: the track id or episode id'),
            'position' => $schema->integer()->description('set: seconds into the item'),
            'limit' => $schema->integer()->description('list: how many (default 30)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:list,set,clear',
            'kind' => 'nullable|in:track,episode', 'id' => 'nullable|integer', 'position' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $need = fn (string $field) => Response::error("invalid (422): {$field} is required.");

        return match ($data['action']) {
            'list' => $this->api('GET', 'library/positions', $this->filled(['limit' => $data['limit'] ?? null])),
            'set' => !isset($data['kind'], $data['id']) ? $need('kind and id')
                : (!isset($data['position']) ? $need('position') : $this->api('PUT', "library/positions/{$data['kind']}/{$data['id']}", ['position' => $data['position']])),
            'clear' => !isset($data['kind'], $data['id']) ? $need('kind and id') : $this->api('DELETE', "library/positions/{$data['kind']}/{$data['id']}"),
        };
    }
}
