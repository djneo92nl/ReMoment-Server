<?php

namespace App\Http\Controllers\Api;

use App\Domain\Artwork\ArtworkCache;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceListResource;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
     * Processed artwork of every library album, for clients to pre-cache by
     * hash. Pages scan ARTWORK_PAGE_SIZE albums in id order (cursor = last
     * album id seen), so a page can hold fewer items, or none, while
     * next_cursor is still set. Albums without processed artwork are skipped;
     * the daily library:backfill-artwork processes them.
     */
    public function artwork(Request $request, string $apiToken): JsonResponse
    {
        Client::where('api_token', $apiToken)->firstOrFail();

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);
        $cursor = (int) ($validated['cursor'] ?? 0);

        $albums = Album::query()
            ->whereNotNull('images')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(self::ARTWORK_PAGE_SIZE)
            ->get(['id', 'images']);

        $urls = $albums
            ->map(fn (Album $album) => $this->coverUrl($album->images ?? []))
            ->filter()
            ->unique()
            ->values();

        $entries = ArtworkCache::getMany($urls->all());

        $items = $urls
            ->map(fn (string $url) => $this->precacheItem($url, $entries[$url] ?? null))
            ->filter()
            ->values();

        return response()->json([
            'data' => $items,
            'next_cursor' => $albums->count() === self::ARTWORK_PAGE_SIZE ? $albums->last()->id : null,
        ]);
    }

    /**
     * From the cache entry, or from the files on disk when the entry expired
     * (cache TTL is 30 days, the files stay) — same URLs either way.
     */
    private function precacheItem(string $url, ?array $entry): ?array
    {
        $hash = md5($url);

        if (isset($entry['proxy_320'], $entry['proxy_120'])) {
            return ['hash' => $hash, 'proxy_320' => $entry['proxy_320'], 'proxy_120' => $entry['proxy_120']];
        }

        $disk = Storage::disk('public');
        $paths = ['proxy_320' => "artwork/{$hash}/320.jpg", 'proxy_120' => "artwork/{$hash}/120.jpg"];

        foreach ($paths as $path) {
            if (!$disk->exists($path)) {
                return null;
            }
        }

        return ['hash' => $hash] + array_map(fn (string $path) => $disk->url($path), $paths);
    }

    private function coverUrl(array $images): ?string
    {
        $first = $images[0] ?? null;

        return (is_array($first) ? ($first['url'] ?? null) : $first) ?: null;
    }

    private function resolveDevices(Client $client)
    {
        $assigned = $client->devices;

        if ($client->type === 'multi' && $assigned->isEmpty()) {
            return Device::orderBy('device_name')->get();
        }

        return $assigned;
    }
}
