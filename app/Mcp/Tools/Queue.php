<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Up Next queue of a device. Actions: `list` (default; needs `queue`), `jump` (to `position`, 1 = next track; `queue_jump`), `add` / `next` (a track, album, artist or playlist at the end / right after the current track; `queue_add` / `queue_next`), `remove` (`position`; `queue_remove`), `move` (`position` to `to`; `queue_move`), `clear` (everything after the current track; `queue_clear`, needs confirm=true). Pass `expect` (the uri listed at that position) to remove or move so a changed queue is refused.')]
class Queue extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'action' => $schema->string()->enum(['list', 'jump', 'add', 'next', 'remove', 'move', 'clear'])->description('Default: jump when `jump_to` is given, else list'),
            'jump_to' => $schema->integer()->description('Position in the up-next list to skip to (same as action=jump with position)')->min(1)->max(100),
            'limit' => $schema->integer()->description('How many up-next tracks to list (1-100, default 20)')->min(1)->max(100),
            'type' => $schema->string()->enum(['track', 'album', 'artist', 'playlist'])->description('What to add (add, next)'),
            'id' => $schema->integer()->description('Id of the track, album, artist or playlist to add (add, next)'),
            'position' => $schema->integer()->description('Up-next position to jump to, remove or move (1 = next track)')->min(1)->max(1000),
            'to' => $schema->integer()->description('Up-next position to move the entry to (move)')->min(1)->max(1000),
            'expect' => $schema->string()->description('The uri listed at `position`; a changed queue is refused (remove, move)'),
            'confirm' => $schema->boolean()->description('Required for clear'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'device_id' => 'required|integer',
            'action' => 'nullable|in:list,jump,add,next,remove,move,clear',
            'jump_to' => 'nullable|integer',
            'limit' => 'nullable|integer',
            'type' => 'nullable|in:track,album,artist,playlist',
            'id' => 'nullable|integer',
            'position' => 'nullable|integer',
            'to' => 'nullable|integer',
            'expect' => 'nullable|string',
            'confirm' => 'nullable|boolean',
        ]);
        $path = 'devices/'.$data['device_id'].'/queue';
        $action = $data['action'] ?? (isset($data['jump_to']) ? 'jump' : 'list');
        $position = $data['position'] ?? $data['jump_to'] ?? null;

        return match ($action) {
            'list' => $this->api('GET', $path, $this->filled(['limit' => $data['limit'] ?? null])),
            'jump' => $position === null ? Response::error('invalid (422): jump needs a position.') : $this->api('POST', "$path/jump", ['position' => $position]),
            'add', 'next' => isset($data['type'], $data['id'])
                ? $this->api('POST', $path, ['type' => $data['type'], 'id' => $data['id'], 'next' => $action === 'next'])
                : Response::error("invalid (422): {$action} needs a type and an id."),
            'remove' => $position === null ? Response::error('invalid (422): remove needs a position.') : $this->api('DELETE', "$path/$position", $this->filled(['expect' => $data['expect'] ?? null])),
            'move' => $position === null || !isset($data['to'])
                ? Response::error('invalid (422): move needs a position and `to`.')
                : $this->api('PUT', "$path/$position", $this->filled(['to' => $data['to'], 'expect' => $data['expect'] ?? null])),
            'clear' => ($data['confirm'] ?? false)
                ? $this->api('DELETE', $path)
                : Response::error('confirmation_required (422): this empties the queue after the current track; call again with confirm=true.'),
        };
    }
}
