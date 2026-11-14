<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Listening insights of the household (GET /api/library/insights, docs/api/insights.md): skip rate per artist, new against repeat tracks per week, mood and tempo per part of the day, and what is played late at night. Household-wide, never per person. Last `days` days (default 90, at most 365). Mood and tempo need audio analysis (Full profile), else those blocks are empty. Standard and Full only; Control answers not_available_in_profile.')]
#[IsReadOnly]
class ListeningInsights extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Window in days ending today, 1-365, default 90')->min(1)->max(365),
            'scope' => $schema->string()->description('local, streaming, all or a library id; default is the leading source'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(['days' => 'nullable|integer|min:1|max:365', 'scope' => 'nullable|string|max:50']);

        return $this->api('GET', 'library/insights', $this->filled($data));
    }
}
