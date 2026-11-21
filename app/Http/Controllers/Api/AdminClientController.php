<?php

namespace App\Http\Controllers\Api;

use App\Domain\Clients\ClientAdmin;
use App\Domain\Library\LibrarySources;
use App\Domain\Node\NodeRoles;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Client registrations for apps (docs/api/admin-clients.md), admin token only: the same actions as /settings/clients,
 * through Domain\Clients\ClientAdmin. The client's own side (register, status, heartbeat) is `ClientController`.
 */
class AdminClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in([ClientAdmin::PENDING, ClientAdmin::APPROVED])]]);

        $clients = ClientAdmin::all()->when($data['status'] ?? null, fn ($all, $status) => $all->where('status', $status));

        return response()->json([
            'data' => $clients->map(fn (Client $c) => ClientAdmin::toArray($c))->values(),
            'pending' => $clients->where('status', ClientAdmin::PENDING)->count(),
        ]);
    }

    public function show(Client $client): JsonResponse
    {
        return response()->json(['data' => ClientAdmin::toArray($client, withToken: true)]);
    }

    public function approve(Request $request, Client $client): JsonResponse
    {
        if ($client->status !== ClientAdmin::PENDING) {
            return $this->error('not_pending', 'Only a pending registration can be approved.', 409);
        }

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(['single', 'multi'])],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // A node's declared roles to switch on; leave out for none.
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::in(NodeRoles::names())],
        ]);

        return response()->json(['data' => ClientAdmin::toArray(ClientAdmin::approve($client, $data), withToken: true)]);
    }

    /** Rejects a pending registration (removes it). An approved client is removed with DELETE. */
    public function reject(Client $client): JsonResponse
    {
        if ($client->status !== ClientAdmin::PENDING) {
            return $this->error('not_pending', 'Only a pending registration can be rejected; DELETE removes an approved client.', 409);
        }

        ClientAdmin::delete($client);

        return response()->json(['status' => 'ok']);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['single', 'multi'])],
            'device_ids' => ['sometimes', 'array'],
            'device_ids.*' => ['integer', 'exists:devices,id'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::in(NodeRoles::names())],
            'hidden_sources' => ['sometimes', 'array'],
            'hidden_sources.*' => ['string', Rule::in(array_keys(LibrarySources::all()))],
        ]);

        return response()->json(['data' => ClientAdmin::toArray(ClientAdmin::update($client, $data))]);
    }

    public function regenerateToken(Client $client): JsonResponse
    {
        if ($client->status !== ClientAdmin::APPROVED) {
            return $this->error('not_approved', 'A pending registration has no API token yet.', 409);
        }

        return response()->json(['data' => ClientAdmin::toArray(ClientAdmin::regenerateToken($client), withToken: true)]);
    }

    public function destroy(Client $client): JsonResponse
    {
        ClientAdmin::delete($client);

        return response()->json(['status' => 'ok']);
    }

    private function error(string $error, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
