<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Add, change and remove devices, and the discovery inbox (docs/api/admin-devices.md); reading devices is `list_devices`. action: models (what a device can be added as: brand, product, virtual), list (every device, hidden ones too), add (device_name, device_brand_name, device_product_type from `models`, ip_address unless virtual), update (id; any of device_name, ip_address, hidden, or brand + product together), remove (id; confirm=true), found (the inbox and the state of the last scan), scan (queue a network scan, optional hosts to probe directly; poll `found`), add_found (found_id; optional name), add_all_found, ignore_found (found_id), restore_found (found_id). Goes through the admin REST API.')]
class ManageDevices extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['models', 'list', 'add', 'update', 'remove', 'found', 'scan', 'add_found', 'add_all_found', 'ignore_found', 'restore_found'])->required(),
            'id' => $schema->integer()->description('update, remove: the device id'),
            'found_id' => $schema->integer()->description('add_found, ignore_found, restore_found: the id from `found`'),
            'device_name' => $schema->string()->description('add, update'),
            'name' => $schema->string()->description('add_found: the name to give it (default: the name it was found with)'),
            'device_brand_name' => $schema->string()->description('add, update (with the product)'),
            'device_product_type' => $schema->string()->description('add, update (with the brand)'),
            'ip_address' => $schema->string()->description('add, update'),
            'hidden' => $schema->boolean()->description('update: leave the device out of the pickers'),
            'hosts' => $schema->array()->items($schema->string())->description('scan: addresses to probe directly when multicast does not reach the server'),
            'confirm' => $schema->boolean()->description('remove: must be true'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:models,list,add,update,remove,found,scan,add_found,add_all_found,ignore_found,restore_found',
            'id' => 'nullable|integer', 'found_id' => 'nullable|integer', 'device_name' => 'nullable|string', 'name' => 'nullable|string',
            'device_brand_name' => 'nullable|string', 'device_product_type' => 'nullable|string', 'ip_address' => 'nullable|string',
            'hidden' => 'nullable|boolean', 'hosts' => 'nullable|array', 'hosts.*' => 'string', 'confirm' => 'nullable|boolean',
        ]);

        $needs = ['update' => 'id', 'remove' => 'id', 'add_found' => 'found_id', 'ignore_found' => 'found_id', 'restore_found' => 'found_id'][$data['action']] ?? null;
        if ($needs !== null && !isset($data[$needs])) {
            return Response::error("invalid (422): {$data['action']} needs {$needs}.");
        }
        if ($data['action'] === 'remove' && !($data['confirm'] ?? false)) {
            return Response::error('confirmation_required (409): removing a device drops its settings, links and listener; call again with confirm=true.');
        }

        $fields = fn (array $keys) => $this->filled(array_intersect_key($data, array_flip($keys)));

        return match ($data['action']) {
            'models' => $this->api('GET', 'admin/device-models'),
            'list' => $this->api('GET', 'admin/devices'),
            'add' => $this->api('POST', 'admin/devices', $fields(['device_name', 'device_brand_name', 'device_product_type', 'ip_address', 'hidden'])),
            'update' => $this->api('PUT', "admin/devices/{$data['id']}", $fields(['device_name', 'ip_address', 'hidden', 'device_brand_name', 'device_product_type'])),
            'remove' => $this->api('DELETE', "admin/devices/{$data['id']}"),
            'found' => $this->api('GET', 'admin/discovery'),
            'scan' => $this->api('POST', 'admin/discovery/scan', $fields(['hosts'])),
            'add_found' => $this->api('POST', "admin/discovery/{$data['found_id']}/add", $fields(['name'])),
            'add_all_found' => $this->api('POST', 'admin/discovery/add-all'),
            'ignore_found' => $this->api('POST', "admin/discovery/{$data['found_id']}/ignore"),
            default => $this->api('POST', "admin/discovery/{$data['found_id']}/restore"),
        };
    }
}
