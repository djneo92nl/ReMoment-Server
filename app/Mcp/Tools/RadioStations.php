<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Manage the radio stations themselves (docs/api/admin-radio.md); playing one and its favourite are the `radio` tool. action: list (optional q = name filter; every station with its identifiers), get (id), add (name; optional genre, image_url, identifiers = platform => id such as {"url": "https://…/stream.mp3", "beoradio": "…"}), update (id; an empty identifier removes it), remove (id; confirm=true), search (q: the stream finder, radio-browser.info), add_found (a search result: name, url; optional image_url, genre, uuid; adds once per stream URL). Goes through the admin REST API.')]
class RadioStations extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'add', 'update', 'remove', 'search', 'add_found'])->required(),
            'id' => $schema->integer()->description('get, update, remove'),
            'q' => $schema->string()->description('list, search'),
            'name' => $schema->string()->description('add, update, add_found'),
            'genre' => $schema->string()->description('add, update, add_found'),
            'image_url' => $schema->string()->description('add, update, add_found'),
            'identifiers' => $schema->object()->description('add, update: platform => identifier (url, beoradio, tunein ...)'),
            'url' => $schema->string()->description('add_found: the stream URL of the search result'),
            'uuid' => $schema->string()->description('add_found: the radio-browser.info id of the result'),
            'confirm' => $schema->boolean()->description('remove: must be true'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:list,get,add,update,remove,search,add_found', 'id' => 'nullable|integer', 'q' => 'nullable|string', 'name' => 'nullable|string',
            'genre' => 'nullable|string', 'image_url' => 'nullable|string', 'identifiers' => 'nullable|array', 'url' => 'nullable|string', 'uuid' => 'nullable|string', 'confirm' => 'nullable|boolean',
        ]);

        if (in_array($data['action'], ['get', 'update', 'remove'], true) && !isset($data['id'])) {
            return Response::error("invalid (422): {$data['action']} needs id.");
        }
        if ($data['action'] === 'search' && ($data['q'] ?? '') === '') {
            return Response::error('invalid (422): search needs q.');
        }
        if ($data['action'] === 'remove' && !($data['confirm'] ?? false)) {
            return Response::error('confirmation_required (409): removing a station forgets its play log link and favourite; call again with confirm=true.');
        }

        $fields = fn (array $keys) => $this->filled(array_intersect_key($data, array_flip($keys)));

        return match ($data['action']) {
            'list' => $this->api('GET', 'admin/radio-stations', $fields(['q'])),
            'get' => $this->api('GET', "admin/radio-stations/{$data['id']}"),
            'add' => $this->api('POST', 'admin/radio-stations', $fields(['name', 'genre', 'image_url', 'identifiers'])),
            'update' => $this->api('PUT', "admin/radio-stations/{$data['id']}", $fields(['name', 'genre', 'image_url', 'identifiers'])),
            'search' => $this->api('GET', 'admin/radio-stations/search', $fields(['q'])),
            'add_found' => $this->api('POST', 'admin/radio-stations/directory', $fields(['name', 'url', 'image_url', 'genre', 'uuid'])),
            default => $this->api('DELETE', "admin/radio-stations/{$data['id']}"),
        };
    }
}
