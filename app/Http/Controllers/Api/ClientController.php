<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Artwork\NowPlayingArtwork;
use App\Domain\Artwork\PlaylistArtwork;
use App\Domain\Artwork\SourceLogo;
use App\Domain\Device\SpotifyRouting;
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
        ]);

        $ip = $request->ip();

        if (!empty($data['hardware_id'])) {
            $existing = Client::where('hardware_id', $data['hardware_id'])->first();
            if ($existing) {
                $existing->update(array_merge($data, [
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

        $client = Client::create(array_merge($data, [
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

        return response()->json([
            'status' => 'approved',
            'type' => $client->type,
            'api_token' => $client->api_token,
            'devices' => DeviceListResource::collection($devices),
        ]);
    }

    public function devices(string $apiToken): JsonResponse
    {
        $client = Client::where('api_token', $apiToken)->firstOrFail();
        $client->update(['last_seen_at' => now()]);

        return response()->json([
            'devices' => DeviceListResource::collection($this->resolveDevices($client)),
        ]);
    }

    public function heartbeat(Request $request, string $apiToken): JsonResponse
    {
        $client = Client::where('api_token', $apiToken)->firstOrFail();

        $data = $request->validate([
            'firmware_version' => ['nullable', 'string', 'max:50'],
            'build_number' => ['nullable', 'integer', 'min:0'],
        ]);

        $client->update(array_merge($data, [
            'ip_address' => $request->ip(),
            'last_seen_at' => now(),
        ]));

        return response()->json(['status' => 'ok']);
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
        Client::where('api_token', $apiToken)->firstOrFail();

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);
        $offset = (int) ($validated['cursor'] ?? 0);

        $albums = LibraryArtwork::albumsByRecency()
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

        if ($client->type === 'multi' && $assigned->isEmpty()) {
            $assigned = Device::orderBy('device_name')->get();
        }

        return SpotifyRouting::visible($assigned);
    }
}
