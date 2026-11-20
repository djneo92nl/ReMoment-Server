<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Sub devices: a device connected to a host (a Chromecast on a TV\'s HDMI input, a BeoLink Converter a player borrows); docs/api/sub-devices.md. action: list (device_id: its links as host and as sub device), suggestions (device_id: the devices of yours an ASE player lists in its Products), add (device_id = the sub device, host_device_id, kind borrow|input|source, via = the host\'s input or source id such as hdmi_1; optional meta), update (id; via and/or meta), remove (id). Everyone sees the relation in get_device (host, sub_devices). Goes through the admin REST API, so its answers and errors are the same.')]
class DeviceLinks extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'suggestions', 'add', 'update', 'remove'])->required(),
            'device_id' => $schema->integer()->description('list, suggestions: the device; add: the sub device'),
            'host_device_id' => $schema->integer()->description('add: the host'),
            'id' => $schema->integer()->description('update, remove: the link id'),
            'kind' => $schema->string()->enum(['borrow', 'input', 'source'])->description('add'),
            'via' => $schema->string()->description('add, update: the host input or source id (hdmi_1); empty for a borrow link covering every source'),
            'meta' => $schema->object()->description('add, update: link actions and the follow rule (docs/architecture/sub-devices.md)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:list,suggestions,add,update,remove',
            'device_id' => 'nullable|integer', 'host_device_id' => 'nullable|integer', 'id' => 'nullable|integer',
            'kind' => 'nullable|in:borrow,input,source', 'via' => 'nullable|string', 'meta' => 'nullable|array',
        ]);

        $needs = ['list' => 'device_id', 'suggestions' => 'device_id', 'update' => 'id', 'remove' => 'id'][$data['action']] ?? null;
        if ($needs !== null && !isset($data[$needs])) {
            return Response::error("invalid (422): {$data['action']} needs {$needs}.");
        }

        return match ($data['action']) {
            'list' => $this->api('GET', "admin/devices/{$data['device_id']}/links"),
            'suggestions' => $this->api('GET', "admin/devices/{$data['device_id']}/link-suggestions"),
            'add' => $this->api('POST', 'admin/device-links', $this->filled(array_intersect_key($data, array_flip(['device_id', 'host_device_id', 'kind', 'via', 'meta'])))),
            'update' => $this->api('PUT', "admin/device-links/{$data['id']}", $this->filled(array_intersect_key($data, array_flip(['via', 'meta'])))),
            default => $this->api('DELETE', "admin/device-links/{$data['id']}"),
        };
    }
}
