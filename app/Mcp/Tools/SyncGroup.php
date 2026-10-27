<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Sync groups ("fake multiroom") for devices without native multiroom: a leader plays, the members play the same queue and are kept in step by the server (state, track changes, progress within about 2 seconds; radio is started together, not corrected). `list` groups, `create` (device_id = leader, member_ids), `add` a member (group_id, device_id), `remove` a member, `offset` trims a member in milliseconds (positive = ahead of the leader), `dissolve` (group_id; members are stopped unless stop_members is false). Only library tracks and radio can be shared, not Spotify. Needs the `sync_group` capability on every device. Errors: invalid (422).')]
class SyncGroup extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'create', 'add', 'remove', 'offset', 'dissolve'])->required(),
            'group_id' => $schema->integer()->description('add, remove, offset, dissolve'),
            'device_id' => $schema->integer()->description('create: the leader; add, remove, offset: the member'),
            'member_ids' => $schema->array()->items($schema->integer())->description('create: the devices that follow'),
            'name' => $schema->string()->description('create: optional group name'),
            'offset_ms' => $schema->integer()->description('offset: -30000 to 30000'),
            'stop_members' => $schema->boolean()->description('dissolve, remove: stop the member(s) playing (default true)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:list,create,add,remove,offset,dissolve',
            'group_id' => 'nullable|integer',
            'device_id' => 'nullable|integer',
            'member_ids' => 'nullable|array',
            'member_ids.*' => 'integer',
            'name' => 'nullable|string|max:100',
            'offset_ms' => 'nullable|integer',
            'stop_members' => 'nullable|boolean',
        ]);

        $group = 'sync-groups/'.($data['group_id'] ?? 0);
        $stop = array_filter(['stop' => $data['stop_members'] ?? null], fn ($v) => $v !== null);

        return match ($data['action']) {
            'create' => $this->api('POST', 'sync-groups', array_filter([
                'leader_device_id' => $data['device_id'] ?? null,
                'member_device_ids' => $data['member_ids'] ?? [],
                'name' => $data['name'] ?? null,
            ], fn ($v) => $v !== null)),
            'add' => $this->api('POST', "$group/members", ['device_id' => $data['device_id'] ?? null]),
            'remove' => $this->api('DELETE', "$group/members/".($data['device_id'] ?? 0), $stop),
            'offset' => $this->api('PUT', "$group/members/".($data['device_id'] ?? 0), ['offset_ms' => $data['offset_ms'] ?? 0]),
            'dissolve' => $this->api('DELETE', $group, array_filter(['stop_members' => $data['stop_members'] ?? null], fn ($v) => $v !== null)),
            default => $this->api('GET', 'sync-groups'),
        };
    }
}
