<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The MusicBrainz discography of the library: what an artist released (albums and EPs) and whether each is owned, streamable or only known (missing), and the household wishlist. action: discography (needs artist_id; optional availability owned|streamable|missing, wanted, ignored), wishlist (optional cursor), item (needs id, a catalogue entry). Pass device_id to see where each entry can play. Full shapes: docs/api/availability.md.')]
#[IsReadOnly]
class Catalogue extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['discography', 'wishlist', 'item'])->required(),
            'artist_id' => $schema->integer()->description('discography: the library artist'),
            'id' => $schema->integer()->description('item: the catalogue entry id'),
            'availability' => $schema->string()->enum(['owned', 'streamable', 'missing'])->description('discography filter; missing = not in the library'),
            'wanted' => $schema->boolean()->description('discography: only wished entries'),
            'ignored' => $schema->boolean()->description('discography: include entries marked "not interested"'),
            'cursor' => $schema->string()->description('wishlist: next_cursor of the previous page'),
            'device_id' => $schema->integer()->description('Mark where each entry can play'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:discography,wishlist,item',
            'artist_id' => 'nullable|integer', 'id' => 'nullable|integer',
            'availability' => 'nullable|in:owned,streamable,missing', 'wanted' => 'nullable|boolean', 'ignored' => 'nullable|boolean',
            'cursor' => 'nullable|string', 'device_id' => 'nullable|integer',
        ]);

        $common = $this->filled(['device_id' => $data['device_id'] ?? null]);

        return match ($data['action']) {
            'wishlist' => $this->api('GET', 'library/wishlist', $common + $this->filled(['cursor' => $data['cursor'] ?? null])),
            'item' => isset($data['id'])
                ? $this->api('GET', 'library/catalogue/'.$data['id'], $common)
                : Response::error('invalid (422): item needs an id.'),
            default => isset($data['artist_id'])
                ? $this->api('GET', 'library/artists/'.$data['artist_id'].'/discography', $common + $this->filled([
                    'availability' => $data['availability'] ?? null,
                    'wanted' => isset($data['wanted']) ? (int) $data['wanted'] : null,
                    'ignored' => isset($data['ignored']) ? (int) $data['ignored'] : null,
                ]))
                : Response::error('invalid (422): discography needs an artist_id.'),
        };
    }
}
