<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Listening statistics of the household from the play log: totals, by hour, weekday, day, source and device, and top lists (GET /api/library/stats, docs/api/stats.md). Household-wide, never per person; "plays logged", not plays heard. Default range: the last 30 days, at most 731. Open to everyone, also in Control.')]
#[IsReadOnly]
class ListeningStats extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('First day, Y-m-d (default 29 days before `to`)'),
            'to' => $schema->string()->description('Last day, Y-m-d, inclusive (default today)'),
            'limit' => $schema->integer()->description('Length of each top list, 1-50, default 10')->min(1)->max(50),
            'scope' => $schema->string()->description('local, streaming, all or a library id; default is the leading source'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'limit' => 'nullable|integer|min:1|max:50', 'scope' => 'nullable|string|max:50',
        ]);

        return $this->api('GET', 'library/stats', $this->filled($data));
    }
}
