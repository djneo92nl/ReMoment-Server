<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('The physical collection: CDs, records and tapes on the shelf. action: list (optional medium cd|tape|vinyl|minidisc|other, q, ripped), lookup (needs barcode: the releases MusicBrainz knows, to choose from), add (needs artist, title, medium; optional year, barcode, catalogue_number, condition, notes, location, acquired_on, release_group_mbid, release_mbid from a lookup), update (needs id and the fields to change; ripped true marks it ripped), remove (needs id). An item makes its album owned and closes a wish for it. Full shapes: docs/api/physical.md.')]
class Physical extends Tool
{
    use CallsApi;

    private const FIELDS = ['artist', 'title', 'medium', 'year', 'barcode', 'catalogue_number', 'condition', 'notes', 'location', 'acquired_on', 'release_group_mbid', 'release_mbid', 'ripped'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'lookup', 'add', 'update', 'remove'])->required(),
            'id' => $schema->integer()->description('update, remove: the item id'),
            'barcode' => $schema->string()->description('lookup, add: the barcode'),
            'medium' => $schema->string()->enum(['cd', 'tape', 'vinyl', 'minidisc', 'other']),
            'artist' => $schema->string(), 'title' => $schema->string(), 'year' => $schema->integer(),
            'catalogue_number' => $schema->string(), 'condition' => $schema->string(), 'notes' => $schema->string(), 'location' => $schema->string(),
            'acquired_on' => $schema->string()->description('YYYY-MM-DD'),
            'release_group_mbid' => $schema->string(), 'release_mbid' => $schema->string(),
            'ripped' => $schema->boolean()->description('update, list: ripped to files'),
            'q' => $schema->string()->description('list: search artist, title or barcode'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:list,lookup,add,update,remove', 'id' => 'nullable|integer', 'q' => 'nullable|string|max:100',
            'medium' => 'nullable|in:cd,tape,vinyl,minidisc,other', 'year' => 'nullable|integer', 'ripped' => 'nullable|boolean',
            'barcode' => 'nullable|string', 'artist' => 'nullable|string', 'title' => 'nullable|string', 'catalogue_number' => 'nullable|string',
            'condition' => 'nullable|string', 'notes' => 'nullable|string', 'location' => 'nullable|string', 'acquired_on' => 'nullable|string',
            'release_group_mbid' => 'nullable|string', 'release_mbid' => 'nullable|string',
        ]);
        $fields = $this->filled(array_intersect_key($data, array_flip(self::FIELDS)));

        return match ($data['action']) {
            'list' => $this->api('GET', 'library/physical', $this->filled(['medium' => $data['medium'] ?? null, 'q' => $data['q'] ?? null, 'ripped' => isset($data['ripped']) ? (int) $data['ripped'] : null])),
            'lookup' => ($data['barcode'] ?? '') === '' ? Response::error('invalid (422): lookup needs a barcode.') : $this->api('GET', 'library/physical/lookup', ['barcode' => $data['barcode']]),
            'add' => $this->api('POST', 'library/physical', $fields),
            'update' => isset($data['id']) ? $this->api('PUT', 'library/physical/'.$data['id'], $fields) : Response::error('invalid (422): update needs an id.'),
            default => isset($data['id']) ? $this->api('DELETE', 'library/physical/'.$data['id']) : Response::error('invalid (422): remove needs an id.'),
        };
    }
}
