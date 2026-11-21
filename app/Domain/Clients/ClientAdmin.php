<?php

namespace App\Domain\Clients;

use App\Domain\Library\LibrarySources;
use App\Domain\Node\NodeRoles;
use App\Models\Client;
use Illuminate\Support\Collection;

/**
 * What the admin does with client registrations (docs/api/admin-clients.md): approve, reject, edit, regenerate the token,
 * delete. `Livewire\ClientManager` (/settings/clients) and the admin API (`Api\AdminClientController`) both call this,
 * so there is one implementation of what an approval or an edit does.
 */
class ClientAdmin
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    /** @return Collection<int, Client> pending first, then newest first */
    public static function all(): Collection
    {
        return Client::with('devices')
            ->orderByRaw("status = 'pending' DESC")
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Approves a pending registration: it gets its API token now (the client collects it from its status poll).
     * A node's declared roles are switched on only when listed in `roles` (default: none of them; the web form ticks them all first).
     *
     * @param  array{type?: string, name?: ?string, roles?: list<string>}  $data
     */
    public static function approve(Client $client, array $data = []): Client
    {
        $client->update([
            'enabled_roles' => $client->isNode()
                ? NodeRoles::normalize(array_intersect($data['roles'] ?? [], $client->roles ?? []))
                : null,
            'status' => self::APPROVED,
            'type' => $data['type'] ?? 'single',
            'name' => self::name($data['name'] ?? null),
            'api_token' => Client::generateToken(),
            'approved_at' => now(),
        ]);

        return $client->refresh();
    }

    /**
     * Changes what the admin may change. Only the keys present are touched: `name`, `type` (single|multi),
     * `device_ids` (a `single` client keeps at most one), `roles` (a node's switched-on roles, within what it declared)
     * and `hidden_sources` (library ids the client does not see).
     *
     * @param  array{name?: ?string, type?: string, device_ids?: list<int>, roles?: list<string>, hidden_sources?: list<string>}  $data
     */
    public static function update(Client $client, array $data): Client
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = self::name($data['name']);
        }
        if (array_key_exists('type', $data)) {
            $attributes['type'] = $data['type'];
        }
        if (array_key_exists('roles', $data)) {
            $attributes['enabled_roles'] = $client->isNode()
                ? NodeRoles::normalize(array_intersect($data['roles'], $client->roles ?? []))
                : null;
        }
        if (array_key_exists('hidden_sources', $data)) {
            $attributes['hidden_sources'] = LibrarySources::normalize($data['hidden_sources']) ?: null;
        }

        $client->update($attributes);

        $type = $attributes['type'] ?? $client->type;
        if (array_key_exists('device_ids', $data) || ($type === 'single' && $client->devices()->count() > 1)) {
            $deviceIds = array_map('intval', $data['device_ids'] ?? $client->devices()->pluck('devices.id')->all());
            if ($type === 'single' && count($deviceIds) > 1) {
                $deviceIds = [reset($deviceIds)];
            }
            $client->devices()->sync($deviceIds);
        }

        return $client->refresh();
    }

    /** A new API token: the client's old one stops working at once. */
    public static function regenerateToken(Client $client): Client
    {
        $client->update(['api_token' => Client::generateToken()]);

        return $client->refresh();
    }

    /** Rejects a registration or removes a client: the row, and with it the token. */
    public static function delete(Client $client): void
    {
        $client->delete();
    }

    /**
     * The client as the admin API shows it. The API token only with `$withToken` (the answers of approve and regenerate,
     * and the detail), never in the list.
     *
     * @return array<string, mixed>
     */
    public static function toArray(Client $client, bool $withToken = false): array
    {
        $client->loadMissing('devices');

        return [
            'id' => $client->id,
            'name' => $client->name,
            'kind' => $client->kind ?? NodeRoles::KIND_CLIENT,
            'type' => $client->type,
            'status' => $client->status,
            'pairing_code' => $client->status === self::PENDING ? $client->pairing_code : null,
            'hardware_id' => $client->hardware_id,
            'ip_address' => $client->ip_address,
            'firmware_version' => $client->firmware_version,
            'build_number' => $client->build_number,
            'battery' => $client->battery_percent === null ? null : ['level' => $client->battery_percent, 'charging' => (bool) $client->battery_charging],
            'last_seen_at' => $client->last_seen_at?->toIso8601String(),
            'approved_at' => $client->approved_at?->toIso8601String(),
            'created_at' => $client->created_at?->toIso8601String(),
            'devices' => $client->devices->map(fn ($d) => ['id' => $d->id, 'name' => $d->device_name])->values()->all(),
            'hidden_sources' => LibrarySources::hiddenFor($client),
            'roles' => $client->roles ?? [],
            'enabled_roles' => $client->activeRoles(),
        ] + ($withToken ? ['api_token' => $client->api_token] : []);
    }

    private static function name(?string $name): ?string
    {
        return $name === null || trim($name) === '' ? null : trim($name);
    }
}
