<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Sync keeps a target filled with a rule-defined part of the library (docs/api/sync.md): a USB stick on a node (kind node) or the iOS app (kind app). list shows the targets with their last run and owned totals; get (id) one target with its rules; create (name, kind, node_id for a node, client_ref for an app, rules) makes one; update (id, name and/or rules) changes it; delete (id) removes it (files on the target stay); estimate (id, optional rules) shows what the rules would hold: tracks, bytes, copied, transcoded, over_budget, warnings, nothing stored; run (id, confirm) starts a sync on the node of a node target (confirm lets mass deletion through); cancel (id) stops it; scan (id) reads the drive (marker, free space) and claim (id) writes the marker on an unmarked drive ("use this drive"), their answers are in the node job (the `nodes` job list). Rules: selections (list in priority order of {type: playlist|album|artist, id} | {type: favorites} | {type: recently_added|most_played, limit, days}), profile (an export profile, e.g. mp3-192), options {loudness off|album|track, layout tree|flat}, budget {bytes, reserve_percent}, fill (all | priority | fill_rest), max_churn_percent. The plan itself is stateless and posted by the executor (the node or the app) with what it holds, so it is not a tool. Goes through the admin REST API, so its answers and errors are the same.')]
class Sync extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'create', 'update', 'delete', 'estimate', 'run', 'cancel', 'scan', 'claim'])->required(),
            'id' => $schema->string()->description('get/update/delete/estimate/run/cancel/scan/claim: the target id (a ULID)'),
            'name' => $schema->string()->description('create/update: the target name'),
            'kind' => $schema->string()->enum(['app', 'node'])->description('create: a node (USB stick on a ReMoment Node) or an app install'),
            'node_id' => $schema->integer()->description('create for a node: the node (client) id; it must declare the sync-target role'),
            'client_ref' => $schema->string()->description('create for an app: the install id the app keeps'),
            'rules' => $schema->object()->description('create/update/estimate: the rules, see the tool description'),
            'confirm' => $schema->boolean()->description('run: let a plan that removes many files go through'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:list,get,create,update,delete,estimate,run,cancel,scan,claim',
            'id' => 'nullable|string', 'name' => 'nullable|string|max:100', 'kind' => 'nullable|in:app,node', 'node_id' => 'nullable|integer',
            'client_ref' => 'nullable|string|max:64', 'rules' => 'nullable|array', 'confirm' => 'nullable|boolean',
        ]);

        $action = $data['action'];
        if ($action === 'list') {
            return $this->api('GET', 'admin/sync/targets');
        }
        if ($action === 'create') {
            return $this->api('POST', 'admin/sync/targets', $this->filled(Arr::only($data, ['name', 'kind', 'node_id', 'client_ref', 'rules'])));
        }
        if (!isset($data['id'])) {
            return Response::error('invalid (422): id is required.');
        }

        $base = 'admin/sync/targets/'.$data['id'];

        return match ($action) {
            'get' => $this->api('GET', $base),
            'update' => $this->api('PUT', $base, $this->filled(Arr::only($data, ['name', 'rules']))),
            'delete' => $this->api('DELETE', $base),
            'estimate' => $this->api('POST', "{$base}/estimate", $this->filled(['rules' => $data['rules'] ?? null])),
            'run' => $this->api('POST', "{$base}/run", $this->filled(['confirm' => $data['confirm'] ?? null])),
            'cancel' => $this->api('DELETE', "{$base}/run"),
            'scan', 'claim' => $this->api('POST', "{$base}/volume", ['action' => $action]),
        };
    }
}
