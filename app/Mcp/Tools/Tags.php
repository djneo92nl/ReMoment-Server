<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Models\AdminToken;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Tap to play: NFC tags and QR codes that play an album, artist, playlist, track, radio station or physical item. action: get (needs uid), play (needs uid; optional device_id, else the tag\'s own device), and with the admin token list, last_scanned (an unknown tag that was just tapped), add (needs target_type and target_id; optional uid (empty: a QR code), label, device_id, shuffle), update (needs id), remove (needs id). Full shapes: docs/api/tags.md.')]
class Tags extends Tool
{
    use CallsApi;

    private const ADMIN = ['list', 'last_scanned', 'add', 'update', 'remove'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['get', 'play', 'list', 'last_scanned', 'add', 'update', 'remove'])->required(),
            'uid' => $schema->string()->description('get, play, add: the tag id'),
            'id' => $schema->integer()->description('update, remove: the tag record id'),
            'device_id' => $schema->integer()->description('play: where; add, update: the tag\'s fixed device'),
            'target_type' => $schema->string()->enum(['album', 'artist', 'playlist', 'track', 'radio', 'physical']),
            'target_id' => $schema->integer(),
            'label' => $schema->string(),
            'shuffle' => $schema->boolean(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:get,play,list,last_scanned,add,update,remove', 'uid' => 'nullable|string|max:64', 'id' => 'nullable|integer',
            'device_id' => 'nullable|integer', 'target_type' => 'nullable|string', 'target_id' => 'nullable|integer',
            'label' => 'nullable|string', 'shuffle' => 'nullable|boolean',
        ]);

        // get and play are open like playback; managing tags needs the admin token (the tool itself stays listed for everyone).
        $plain = request()->bearerToken();
        if (in_array($data['action'], self::ADMIN, true) && !($plain && AdminToken::findPlain($plain))) {
            return Response::error('unauthenticated (401): A valid admin token is required (Authorization: Bearer rmt_… from POST /api/admin/login).');
        }

        $fields = $this->filled(array_intersect_key($data, array_flip(['uid', 'label', 'target_type', 'target_id', 'device_id', 'shuffle'])));
        $uid = rawurlencode((string) ($data['uid'] ?? ''));

        return match ($data['action']) {
            'get' => $uid === '' ? Response::error('invalid (422): get needs a uid.') : $this->api('GET', "tags/{$uid}"),
            'play' => $uid === '' ? Response::error('invalid (422): play needs a uid.') : $this->api('POST', "tags/{$uid}/play", $this->filled(['device_id' => $data['device_id'] ?? null])),
            'list' => $this->api('GET', 'admin/tags'),
            'last_scanned' => $this->api('GET', 'admin/tags/last-scanned'),
            'add' => $this->api('POST', 'admin/tags', $fields),
            'update' => isset($data['id']) ? $this->api('PUT', 'admin/tags/'.$data['id'], $fields) : Response::error('invalid (422): update needs an id.'),
            default => isset($data['id']) ? $this->api('DELETE', 'admin/tags/'.$data['id']) : Response::error('invalid (422): remove needs an id.'),
        };
    }
}
