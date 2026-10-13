<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Search the library by name: artists, albums, tracks and playlists matching the text (min 2 characters). Returns ids to use with browse_library and play_from_library.')]
#[IsReadOnly]
class SearchLibrary extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->description('Text to search for')->min(2)->max(100)->required()];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['query' => 'required|string|min:2|max:100']);

        return $this->api('GET', 'library/search', ['q' => $data['query']]);
    }
}
