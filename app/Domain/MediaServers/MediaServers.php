<?php

namespace App\Domain\MediaServers;

use App\Domain\Credentials\ServiceCredentials;
use App\Integrations\Contracts\MediaServerDriverInterface;
use App\Models\Device;
use App\Models\MediaServer;
use Illuminate\Support\Collection;

/**
 * The registry of media servers (workstream 15, docs/architecture/media-servers.md): the types of
 * config/media-servers.php, the rows of `media_servers` and which of them belong to a device. Read the config only here.
 */
final class MediaServers
{
    /** Device meta key: a JSON list of server ids that device is limited to; absent = every server whose driver can play on it. */
    public const DEVICE_META = 'media_servers';

    /** @return array<string, array<string, mixed>> type => its entry of config/media-servers.php */
    public static function types(): array
    {
        return (array) config('media-servers.drivers', []);
    }

    /** The driver of a server, null for a type that is not registered (a row of a removed driver is skipped, not an error). */
    public static function driver(MediaServer $server): ?MediaServerDriverInterface
    {
        $class = self::types()[$server->driver]['driver'] ?? null;

        return $class ? app()->make($class, ['server' => $server]) : null;
    }

    /** @return list<string> */
    public static function capabilities(MediaServer $server): array
    {
        $driver = self::driver($server);

        return $driver ? MediaServerCapabilities::for($driver) : [];
    }

    /** @return Collection<int, MediaServer> every server, enabled or not */
    public static function all(): Collection
    {
        return MediaServer::query()->orderBy('id')->get();
    }

    /**
     * The servers that apply to a device, in id order: enabled, with complete credentials, a driver that can play on
     * that kind of device and, when the device is limited to some servers (meta `media_servers`), one of those.
     *
     * @return Collection<int, MediaServer>
     */
    public static function forDevice(Device $device, ?string $type = null): Collection
    {
        $limit = self::linkedIds($device);

        return MediaServer::query()
            ->where('enabled', true)
            ->when($type !== null, fn ($q) => $q->where('driver', $type))
            ->orderBy('id')->get()
            ->filter(function (MediaServer $server) use ($device, $limit) {
                $driver = self::driver($server);

                return $driver && $driver->configured() && $driver->canPlayOn($device)
                    && ($limit === null || in_array($server->id, $limit, true));
            })->values();
    }

    /** The server ids a device is limited to, null when it is not. @return list<int>|null */
    public static function linkedIds(Device $device): ?array
    {
        $raw = $device->meta()->where('key', self::DEVICE_META)->value('value');
        $ids = $raw === null ? null : json_decode((string) $raw, true);

        return is_array($ids) ? array_map('intval', array_values($ids)) : null;
    }

    /** @return list<string> the client app packages of the given servers' types */
    public static function clientPackages(iterable $servers): array
    {
        $packages = [];
        foreach ($servers as $server) {
            $packages = array_merge($packages, self::types()[$server->driver]['client_packages'] ?? []);
        }

        return array_values(array_unique($packages));
    }

    /** Whether a package is the client app of any registered type: config only, no query (the Philips listener asks every few seconds). */
    public static function isClientPackage(?string $package): bool
    {
        if ($package === null) {
            return false;
        }

        foreach (self::types() as $type) {
            if (in_array($package, $type['client_packages'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Makes sure the server whose credentials are set has a row: the single Jellyfin server of Settings › Services
     * (the migration does this for an install that already had one, the credentials' `onChanged` hook for one set later).
     * Nothing happens when a row of that type exists (a disabled or renamed one stays as it is) or the credentials are incomplete.
     */
    public static function syncFromCredentials(string $type): ?MediaServer
    {
        $entry = self::types()[$type] ?? null;

        if ($entry === null || MediaServer::query()->where('driver', $type)->exists() || !ServiceCredentials::configured($entry['credentials'])) {
            return null;
        }

        return MediaServer::query()->create([
            'driver' => $type,
            'name' => $entry['label'],
            'credential' => $entry['credentials'],
            'enabled' => true,
        ]);
    }
}
