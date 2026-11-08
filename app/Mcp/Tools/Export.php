<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Export an album, playlist, artist or list of tracks as zip file(s) for an MP3 player or car radio (docs/api/export.md): estimate (type, id or track_ids, profile) shows how many tracks are copied, transcoded or skipped and the size, queues nothing; start queues the export (202, refused with the estimate when over the limits) and answers an id; status (id) shows progress and, when done, the parts with a signed download url valid for 15 minutes (a path: put the server address in front); list shows recent exports; cancel (id) cancels a queued or running export or removes a finished one with its files. type is album | playlist | artist | tracks; profile is highest | mp3-320 (default) | mp3-256 | mp3-192 | mp3-128 | compat. Only tracks with an own file can be exported. Goes through the admin REST API, so its answers and errors are the same.')]
class Export extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['estimate', 'start', 'status', 'list', 'cancel'])->required(),
            'type' => $schema->string()->enum(['album', 'playlist', 'artist', 'tracks'])->description('estimate/start: what to export'),
            'id' => $schema->string()->description('estimate/start: the album, playlist or artist id; status/cancel: the export id (uuid)'),
            'track_ids' => $schema->array()->items($schema->integer())->description('estimate/start with type tracks: library track ids, in order'),
            'profile' => $schema->string()->description('estimate/start: export profile, default mp3-320'),
            'layout' => $schema->string()->enum(['tree', 'flat'])->description('start: folder layout (default: tree for album/artist, flat for playlist/tracks)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:estimate,start,status,list,cancel',
            'type' => 'nullable|in:album,playlist,artist,tracks', 'id' => 'nullable', 'track_ids' => 'nullable|array', 'track_ids.*' => 'integer',
            'profile' => 'nullable|string', 'layout' => 'nullable|in:tree,flat',
        ]);

        $body = fn () => $this->filled([
            'type' => $data['type'] ?? null,
            'id' => ($data['type'] ?? null) === 'tracks' ? null : ($data['id'] ?? null),
            'track_ids' => $data['track_ids'] ?? null,
            'profile' => $data['profile'] ?? null,
            'options' => isset($data['layout']) ? ['layout' => $data['layout']] : null,
        ]);

        return match ($data['action']) {
            'estimate' => $this->api('POST', 'library/exports/estimate', $body()),
            'start' => $this->api('POST', 'library/exports', $body()),
            'list' => $this->api('GET', 'library/exports'),
            'status' => isset($data['id']) ? $this->api('GET', 'library/exports/'.$data['id']) : Response::error('invalid (422): id is required.'),
            'cancel' => isset($data['id']) ? $this->api('DELETE', 'library/exports/'.$data['id']) : Response::error('invalid (422): id is required.'),
        };
    }
}
