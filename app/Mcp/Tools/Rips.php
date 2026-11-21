<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Rip an audio CD on a ReMoment Node with the ripper role and add it to the library (docs/api/rips.md). Steps: `nodes` lists the nodes that can rip (online, disc as of the last check, busy); `check` (node_id) reads the disc in the drive (202, an id); `status` (id) shows the check: state probing, looking_up, no_disc, not_audio, choose_release (with `candidates`: the MusicBrainz releases of this disc) ; `start` (node_id, release_mbid = one candidate) queues the rip (202, an id); `status` follows it (queued, ripping, uploading, done, failed; percent, progress, rip log); `list` shows recent checks and rips; `cancel` (id). The files arrive tagged with the release id and barcode, so the CD of the physical collection is marked ripped. A failed rip raises the alert `rip.failed`. Goes through the admin REST API, so its answers and errors are the same.')]
class Rips extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['nodes', 'check', 'start', 'status', 'list', 'cancel'])->required(),
            'node_id' => $schema->integer()->description('check/start: the node (its id in `nodes`)'),
            'release_mbid' => $schema->string()->description('start: the MusicBrainz release id, one of the candidates of the check'),
            'id' => $schema->string()->description('status/cancel: the id of a check or rip'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:nodes,check,start,status,list,cancel',
            'node_id' => 'nullable|integer', 'release_mbid' => 'nullable|string', 'id' => 'nullable|string',
        ]);

        $need = fn (string $field) => Response::error("invalid (422): {$field} is required.");

        return match ($data['action']) {
            'nodes' => $this->api('GET', 'nodes/rippers'),
            'list' => $this->api('GET', 'admin/rips'),
            'check' => isset($data['node_id']) ? $this->api('POST', 'admin/rips', ['node_id' => $data['node_id']]) : $need('node_id'),
            'start' => !isset($data['node_id']) ? $need('node_id') : (!isset($data['release_mbid']) ? $need('release_mbid')
                : $this->api('POST', 'admin/rips', ['node_id' => $data['node_id'], 'release_mbid' => $data['release_mbid']])),
            'status' => isset($data['id']) ? $this->api('GET', 'admin/rips/'.$data['id']) : $need('id'),
            'cancel' => isset($data['id']) ? $this->api('POST', 'admin/rips/'.$data['id'].'/cancel') : $need('id'),
        };
    }
}
