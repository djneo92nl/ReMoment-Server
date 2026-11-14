<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('What was played, newest first (household-wide, no per-person history; docs/api/history.md). Without album_id/artist_id/device_id: the whole log. With album_id or artist_id: every play of that record. Filters: device_id, from/to (Y-m-d, to inclusive), q (title, artist, album, station or source), source_type (e.g. spotify, radio, dlna), match (library = plays of a library track, unknown = logged as text or a radio stub). Page with next_cursor → cursor. Plays logged skip a quick repeat of the same song, so say "plays logged".')]
#[IsReadOnly]
class PlaybackHistory extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'album_id' => $schema->integer()->description('Only the plays of this album (from browse_library)'),
            'artist_id' => $schema->integer()->description('Only the plays of this artist'),
            'device_id' => $schema->integer()->description('Only the plays on this device'),
            'from' => $schema->string()->description('First day, Y-m-d'),
            'to' => $schema->string()->description('Last day, Y-m-d (inclusive)'),
            'q' => $schema->string()->description('Text in title, artist, album, station or source name'),
            'source_type' => $schema->string()->description('Source type of the play: spotify, radio, dlna, source …'),
            'match' => $schema->string()->enum(['library', 'unknown'])->description('library: plays of a track in the library; unknown: plays that match none'),
            'limit' => $schema->integer()->description('1-100, default 50'),
            'cursor' => $schema->string()->description('next_cursor of the previous page'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'album_id' => 'nullable|integer', 'artist_id' => 'nullable|integer', 'device_id' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'q' => 'nullable|string|max:100',
            'source_type' => 'nullable|string|max:50', 'match' => 'nullable|in:library,unknown',
            'limit' => 'nullable|integer|min:1|max:100', 'cursor' => 'nullable|string|max:200',
        ]);

        $path = match (true) {
            isset($data['album_id']) => "library/albums/{$data['album_id']}/plays",
            isset($data['artist_id']) => "library/artists/{$data['artist_id']}/plays",
            default => 'library/history',
        };

        unset($data['album_id'], $data['artist_id']);

        return $this->api('GET', $path, $this->filled($data));
    }
}
