<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Artwork\SourceLogo;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Library\LibrarySources;
use App\Domain\Node\NodeRoles;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceListResource;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hardware_id' => ['nullable', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'build_number' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
            'kind' => ['nullable', 'in:'.implode(',', NodeRoles::KINDS)],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'in:'.implode(',', NodeRoles::names())],
            'capabilities' => ['nullable', 'array'],
        ]);

        if (strlen((string) json_encode($data['capabilities'] ?? [])) > NodeRoles::MAX_CAPABILITIES_BYTES) {
            return response()->json(['error' => 'too_large', 'message' => 'capabilities is larger than '.NodeRoles::MAX_CAPABILITIES_BYTES.' bytes.'], 422);
        }

        $kind = $data['kind'] ?? null;
        $isNode = $kind === NodeRoles::KIND_NODE;
        if ($isNode && empty($data['hardware_id'])) {
            return response()->json(['error' => 'hardware_id_required', 'message' => 'A node must send a stable hardware_id.'], 422);
        }
        // Roles and capabilities only mean something for a node.
        $nodeData = $isNode ? [
            'roles' => NodeRoles::normalize($data['roles'] ?? []),
            'capabilities' => $data['capabilities'] ?? null,
        ] : [];
        unset($data['kind'], $data['roles'], $data['capabilities']);

        $ip = $request->ip();

        if (!empty($data['hardware_id'])) {
            $existing = Client::where('hardware_id', $data['hardware_id'])->first();
            if ($existing) {
                if ($kind !== null && $kind !== $existing->kind) {
                    return response()->json(['error' => 'kind_mismatch', 'message' => 'This hardware_id is registered as a '.$existing->kind.'; remove it in /settings/clients to register it as a '.$kind.'.'], 422);
                }
                if ($existing->isNode()) {
                    $nodeData['enabled_roles'] = NodeRoles::normalize(array_intersect($existing->enabled_roles ?? [], $nodeData['roles'] ?? []));
                }
                $existing->update(array_merge($data, $nodeData, [
                    'ip_address' => $ip,
                    'pairing_code' => $existing->pairing_code ?? Client::generatePairingCode(),
                ]));

                return response()->json([
                    'registration_token' => $existing->registration_token,
                    'pairing_code' => $existing->pairing_code,
                    'status' => $existing->status,
                ]);
            }
        }

        $client = Client::create(array_merge($data, $nodeData, [
            'kind' => $isNode ? NodeRoles::KIND_NODE : NodeRoles::KIND_CLIENT,
            'ip_address' => $ip,
            'status' => 'pending',
            'registration_token' => Client::generateToken(),
            'pairing_code' => Client::generatePairingCode(),
        ]));

        return response()->json([
            'registration_token' => $client->registration_token,
            'pairing_code' => $client->pairing_code,
            'status' => $client->status,
        ], 201);
    }

    public function status(string $registrationToken): JsonResponse
    {
        $client = Client::where('registration_token', $registrationToken)->firstOrFail();

        if ($client->status === 'pending') {
            return response()->json(['status' => 'pending']);
        }

        $devices = $this->resolveDevices($client);

        return response()->json(array_merge([
            'status' => 'approved',
            'type' => $client->type,
            'api_token' => $client->api_token,
            'devices' => DeviceListResource::collection($devices),
        ], $client->isNode() ? ['kind' => 'node', 'client_id' => $client->id, 'roles' => $client->activeRoles()] : []));
    }

    public function devices(string $apiToken): JsonResponse
    {
        $client = Client::byApiToken($apiToken);
        $client->update(['last_seen_at' => now()]);

        return response()->json([
            'devices' => DeviceListResource::collection($this->resolveDevices($client)),
        ]);
    }

    public function heartbeat(Request $request, string $apiToken): JsonResponse
    {
        $client = Client::byApiToken($apiToken);

        $data = $request->validate([
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'build_number' => ['nullable', 'integer', 'min:0'],
            'battery_percent' => ['nullable', 'integer', 'between:0,100'],
            'battery_charging' => ['nullable', 'boolean'],
            'status' => ['nullable', 'array'],
            'status.roles' => ['nullable', 'array'],
            'status.roles.*.state' => ['nullable', 'in:idle,busy,error,absent'],
            'status.roles.*.detail' => ['nullable', 'string', 'max:200'],
            'status.uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'status.load' => ['nullable', 'numeric', 'min:0'],
            'status.disk_free_bytes' => ['nullable', 'integer', 'min:0'],
        ]);

        // Only a node keeps a status; anything else is ignored. Only known role names are stored.
        $status = $client->isNode() ? ($data['status'] ?? null) : null;
        unset($data['status']);
        if ($status !== null) {
            $status = ['roles' => array_intersect_key($status['roles'] ?? [], array_flip(NodeRoles::names()))]
                + array_intersect_key($status, array_flip(['uptime_seconds', 'load', 'disk_free_bytes']));
            $data['node_status'] = $status;
        }

        $client->update(array_merge($data, [
            'ip_address' => $request->ip(),
            'last_seen_at' => now(),
        ]));

        return response()->json(array_merge(['status' => 'ok'], $client->isNode() ? ['roles' => $client->activeRoles()] : []));
    }

    /** Albums scanned per page of the artwork pre-cache list. */
    public const ARTWORK_PAGE_SIZE = 200;

    /**
     * Processed artwork for clients to pre-cache by hash: the generated
     * source logos and playlist covers (first page only), then library albums most recently
     * played first, then the never-played rest. The cursor is an offset into
     * that album order; a page scans ARTWORK_PAGE_SIZE albums and skips those
     * without processed artwork, so it can hold fewer items, or none, while
     * next_cursor is still set. See docs/api/client-devices.md.
     */
    public function artwork(Request $request, string $apiToken): JsonResponse
    {
        $client = Client::byApiToken($apiToken);

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);
        $offset = (int) ($validated['cursor'] ?? 0);

        $albums = LibrarySources::albums(LibraryArtwork::albumsByRecency(), LibrarySources::hiddenForRequest($client))
            ->offset($offset)
            ->limit(self::ARTWORK_PAGE_SIZE)
            ->get();

        $urls = $albums
            ->map(fn (Album $album) => LibraryArtwork::coverUrl($album->images))
            ->filter()
            ->unique()
            ->values();

        $entries = ArtworkCache::getMany($urls->all());

        $items = $urls
            ->map(fn (string $url) => $this->precacheItem(NowPlayingArtwork::KIND_ALBUM, $url, $entries[$url] ?? null))
            ->filter()
            ->values();

        if ($offset === 0) {
            $logos = collect(SourceLogo::KEYS)
                ->map(fn (string $key) => $this->precacheItem(NowPlayingArtwork::KIND_SOURCE, SourceLogo::url($key), NowPlayingArtwork::logo($key)))
                ->filter();
            $items = $logos->concat($this->playlistItems())->concat($items)->values();
        }

        return response()->json([
            'data' => $items,
            'next_cursor' => $albums->count() === self::ARTWORK_PAGE_SIZE ? $offset + self::ARTWORK_PAGE_SIZE : null,
        ]);
    }

    /**
     * Playlist covers that are not simply an album's cover (those are in the
     * album items): composites and playlists' own images, recent first.
     */
    private function playlistItems(): \Illuminate\Support\Collection
    {
        $urls = PlaylistArtwork::all()
            ->reject(fn (array $choice) => $choice['from'] === PlaylistArtwork::FROM_ALBUM)
            ->pluck('url');
        $entries = ArtworkCache::getMany($urls->all());

        return $urls
            ->map(fn (string $url) => $this->precacheItem(PlaylistArtwork::KIND, $url, $entries[$url] ?? null))
            ->filter()
            ->values();
    }

    private function precacheItem(string $kind, string $url, ?array $entry): ?array
    {
        $files = LibraryArtwork::clientFiles($url, $entry);

        return $files === null ? null : ['kind' => $kind, 'hash' => md5($url)] + $files;
    }

    /**
     * The client's devices; the Spotify virtual device is left out while
     * Spotify is routed to a speaker in the same list (SpotifyRouting::visible).
     */
    private function resolveDevices(Client $client)
    {
        $assigned = $client->devices;

        if ($client->type === 'multi' && !$client->isNode() && $assigned->isEmpty()) {
            $assigned = Device::orderBy('device_name')->get();
        }

        return SpotifyRouting::visible($assigned);
    }
}
