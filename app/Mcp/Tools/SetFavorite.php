<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Favorite or unfavorite an album or an artist (household-wide). Radio stations: use the radio tool.')]
#[IsIdempotent]
class SetFavorite extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['album', 'artist'])->required(),
            'id' => $schema->integer()->required(),
            'favorite' => $schema->boolean()->description('true to favorite, false to remove')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['type' => 'required|in:album,artist', 'id' => 'required|integer', 'favorite' => 'required|boolean']);

        return $this->api('PUT', "library/{$data['type']}s/{$data['id']}/favorite", ['favorite' => (bool) $data['favorite']]);
    }
}
