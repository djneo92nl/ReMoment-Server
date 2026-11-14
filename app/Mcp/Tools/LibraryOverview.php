<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The library at a glance: totals, decades, coverage, sound buckets, formats and how far audio analysis is (GET /api/library/overview, docs/api/overview.md). Cached for a few minutes, so counts can lag a scan. `analysis` is null without audio analysis; a Control install has no library (not_available_in_profile). Open to everyone.')]
#[IsReadOnly]
class LibraryOverview extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->description('local, streaming, all or a library id; default is the leading source'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['scope' => 'nullable|string|max:50']);

        return $this->api('GET', 'library/overview', $this->filled($data));
    }
}
