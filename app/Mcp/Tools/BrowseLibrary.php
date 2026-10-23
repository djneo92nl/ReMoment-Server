<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Browse the library. kind: libraries, recent-items, recent, favorites, random-album, artists, albums, tracks, genres, playlists (lists; page with `cursor`, order with `sort`, jump with `letter`) or artist, album, genre, playlist (detail; needs `id`, genre takes its slug). Pass `device_id` on album/playlist detail to see which tracks that device can play. Full shapes: docs/api/library.md.')]
#[IsReadOnly]
class BrowseLibrary extends Tool
{
    use CallsApi;

    private const LISTS = ['libraries', 'recent-items', 'recent', 'favorites', 'random-album', 'artists', 'albums', 'tracks', 'genres', 'playlists'];

    private const DETAILS = ['artist' => 'artists', 'album' => 'albums', 'genre' => 'genres', 'playlist' => 'playlists'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum([...self::LISTS, ...array_keys(self::DETAILS)])->required(),
            'id' => $schema->string()->description('Artist/album/playlist id, or genre slug (detail kinds)'),
            'cursor' => $schema->string()->description('next_cursor of the previous page'),
            'sort' => $schema->string()->description('artists: name|plays; albums: name|artist|year|added|plays; tracks: name|artist|added|plays'),
            'letter' => $schema->string()->description('A-Z or # to start at that letter (artists, albums)'),
            'scope' => $schema->string()->description('local, streaming, all or a library id; default is the leading source'),
            'device_id' => $schema->integer()->description('Mark what this device can play'),
            'limit' => $schema->integer()->description('recent / recent-items only: 1-100')->min(1)->max(100),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'kind' => 'required|string',
            'id' => 'nullable|string',
            'cursor' => 'nullable|string', 'sort' => 'nullable|string', 'letter' => 'nullable|string', 'scope' => 'nullable|string',
            'device_id' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = $this->filled(array_intersect_key($data, array_flip(['cursor', 'sort', 'letter', 'scope', 'device_id', 'limit'])));

        if (in_array($data['kind'], self::LISTS, true)) {
            return $this->api('GET', 'library/'.$data['kind'], $query);
        }

        if (!isset(self::DETAILS[$data['kind']]) || ($data['id'] ?? '') === '') {
            return Response::error('invalid (422): kind must be one of the list or detail kinds, and detail kinds need an id.');
        }

        return $this->api('GET', 'library/'.self::DETAILS[$data['kind']].'/'.rawurlencode($data['id']), $query);
    }
}
