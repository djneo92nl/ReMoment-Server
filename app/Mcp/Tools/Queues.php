<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). The background-job queues of the server (docs/architecture/queue-management.md, docs/api/admin-queues.md). `list` (default) shows per queue: state (ok, busy, stuck, paused, unknown), waiting/delayed/running jobs, the age of the oldest waiting job, worker processes, failed jobs in the last 24 hours, when a job last finished and whether the queue is lagging. `pause` / `resume` (queue) stop and restart a queue without restarting workers: a job that is running finishes, nothing new starts; `health` cannot be paused. `cancel` (queue, optional job = a job class such as EnrichTrackLyrics) removes the waiting and delayed jobs of that queue or class and answers how many; running jobs are never cancelled. `prioritise` (queue, job) moves the waiting jobs of a class to the front of the queue (redis driver only). Goes through the admin REST API, so its answers and errors are the same.')]
class Queues extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'pause', 'resume', 'cancel', 'prioritise'])->description('Default list'),
            'queue' => $schema->string()->description('pause/resume/cancel/prioritise: the queue name (see list)'),
            'job' => $schema->string()->description('cancel: only jobs of this class (e.g. EnrichTrackLyrics); prioritise: the class to run first'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate(['action' => 'nullable|in:list,pause,resume,cancel,prioritise', 'queue' => 'nullable|string', 'job' => 'nullable|string']);
        $action = $data['action'] ?? 'list';

        if ($action === 'list') {
            return $this->api('GET', 'admin/queues');
        }
        if (!isset($data['queue'])) {
            return Response::error("invalid (422): {$action} needs a queue.");
        }
        if ($action === 'prioritise' && !isset($data['job'])) {
            return Response::error('invalid (422): prioritise needs a job class.');
        }

        return $this->api('POST', 'admin/queues/'.rawurlencode($data['queue'])."/{$action}", $this->filled(['job' => $data['job'] ?? null]));
    }
}
