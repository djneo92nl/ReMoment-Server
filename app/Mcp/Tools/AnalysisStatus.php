<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token; full profile). Audio analysis of the own files, the data and buttons of Settings › Audio analysis (docs/api/admin-analysis.md). action: status (per stage done/pending/failed/gave_up, what runs now, schedule, sidecar), problems (failed stages; optional stage), run (queue one batch now), analyse (file_id; all stages), retry (file_id + stage; forgets its error and queues just that stage). Goes through the admin REST API.')]
class AnalysisStatus extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    private const STAGES = ['loudness', 'waveform', 'quality', 'fingerprint', 'acoustid', 'essentia'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['status', 'problems', 'run', 'analyse', 'retry'])->required(),
            'file_id' => $schema->integer()->description('analyse, retry'),
            'stage' => $schema->string()->enum(self::STAGES)->description('retry (required), problems (filter)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate(['action' => 'required|in:status,problems,run,analyse,retry', 'file_id' => 'nullable|integer', 'stage' => 'nullable|in:'.implode(',', self::STAGES)]);

        if (in_array($data['action'], ['analyse', 'retry'], true) && !isset($data['file_id'])) {
            return Response::error("invalid (422): {$data['action']} needs file_id.");
        }

        return match ($data['action']) {
            'status' => $this->api('GET', 'admin/analysis'),
            'problems' => $this->api('GET', 'admin/analysis/problems', $this->filled(['stage' => $data['stage'] ?? null])),
            'run' => $this->api('POST', 'admin/analysis/run'),
            'analyse' => $this->api('POST', "admin/analysis/files/{$data['file_id']}/analyse"),
            default => $this->api('POST', "admin/analysis/files/{$data['file_id']}/retry", $this->filled(['stage' => $data['stage'] ?? null])),
        };
    }
}
