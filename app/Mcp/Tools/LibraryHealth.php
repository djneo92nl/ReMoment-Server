<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Library health reports on the own audio files (docs/api/library-health.md). Without `report`: how many findings each report has (GET /api/library/health). With `report` (transcodes, damaged, clipping, duplicates, lossy, incomplete, unlinked, missing): its findings, each with a reason and, for albums in the physical collection, that item (the re-rip list); page with `cursor`. Findings need the Full profile (not_available_in_profile elsewhere). Open to everyone.')]
#[IsReadOnly]
class LibraryHealth extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'report' => $schema->string()->enum(['transcodes', 'damaged', 'clipping', 'duplicates', 'lossy', 'incomplete', 'unlinked', 'missing'])->description('Omit for the counts of every report'),
            'cursor' => $schema->string()->description('next_cursor of the previous page'),
            'limit' => $schema->integer()->description('1-200, default 100')->min(1)->max(200),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'report' => 'nullable|string|max:50', 'cursor' => 'nullable|string|max:200', 'limit' => 'nullable|integer|min:1|max:200',
        ]);

        if (($data['report'] ?? '') === '') {
            return $this->api('GET', 'library/health');
        }

        return $this->api('GET', 'library/health/'.rawurlencode($data['report']), $this->filled(array_diff_key($data, ['report' => 1])));
    }
}
