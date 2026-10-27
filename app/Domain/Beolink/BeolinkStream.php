<?php

namespace App\Domain\Beolink;

use App\Domain\Storage\StreamUrl;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The Icecast stream of the beolink container (docs/architecture/beolink-stream.md): the container is a B&O listener
 * that joins one source at a time, and any number of players (speakers, browsers, apps) pull its MP3 mount.
 * The cache holds which B&O device it listens to and which devices were told to play it.
 */
final class BeolinkStream
{
    /** Meta keys a B&O device's JID is kept under (MultiRoomId): ASE and Mozart. */
    private const JID_KEYS = ['ase_jid', 'mozart_jid'];

    private const STATE_TTL_SECONDS = 43200;

    public static function enabled(): bool
    {
        return (bool) config('beolink.enabled');
    }

    /** The address a player pulls the stream from: the host of {@see StreamUrl::base()} on Icecast's port. */
    public static function url(): string
    {
        $base = parse_url(StreamUrl::base());
        $host = $base['host'] ?? '127.0.0.1';

        return sprintf('%s://%s:%d/%s', $base['scheme'] ?? 'http', str_contains($host, ':') ? "[$host]" : $host,
            config('beolink.icecast.port'), ltrim((string) config('beolink.icecast.mount'), '/'));
    }

    public static function title(): string
    {
        return 'BeoLink ('.config('beolink.name').')';
    }

    /** The JID a B&O device is known by on the NetworkLink plane, if we have learned it. */
    public static function jidOf(Device $device): ?string
    {
        return $device->meta()->whereIn('key', self::JID_KEYS)->value('value');
    }

    /** What the stream is doing now: the source device it listens to and the devices playing it. */
    public static function status(): array
    {
        return [
            'enabled' => self::enabled(),
            'name' => config('beolink.name'),
            'url' => self::enabled() ? self::url() : null,
            'host_device_id' => Cache::get('beolink:host'),
            'target_device_ids' => array_values(Cache::get('beolink:targets', [])),
        ];
    }

    /**
     * Make the receiver listen to this B&O device's experience. Asking the receiver to join is asynchronous (the join
     * is carried out by beo's listener role; it answers 202 at once), so a failure shows up as silence on the stream.
     *
     * @throws BeolinkException when the device's JID is unknown or the receiver does not accept the request
     */
    public static function listenTo(Device $host): void
    {
        $jid = self::jidOf($host) ?? throw new BeolinkException("The multiroom id of {$host->device_name} is not known yet (run device:sync-sources).");

        if (Cache::get('beolink:host') === $host->id) {
            return;
        }

        $response = Http::timeout(5)->post(config('beolink.api_url').'/api/v1/beolink/join/'.rawurlencode($jid));

        if (!$response->successful()) {
            throw new BeolinkException('The BeoLink receiver did not accept the join: HTTP '.$response->status().'.');
        }

        Cache::put('beolink:host', $host->id, self::STATE_TTL_SECONDS);
    }

    /** Remember that this device was told to play the stream. */
    public static function addTarget(int $deviceId): void
    {
        $targets = array_values(array_unique([...Cache::get('beolink:targets', []), $deviceId]));
        Cache::put('beolink:targets', $targets, self::STATE_TTL_SECONDS);
    }

    /** Forget a device that stopped playing the stream; the receiver leaves its source when nobody is left. */
    public static function removeTarget(int $deviceId): void
    {
        $targets = array_values(array_diff(Cache::get('beolink:targets', []), [$deviceId]));

        if ($targets !== []) {
            Cache::put('beolink:targets', $targets, self::STATE_TTL_SECONDS);

            return;
        }

        Cache::forget('beolink:targets');
        self::leave();
    }

    /**
     * What the receiver says about itself and the B&O products it has heard (its Mozart API), or null while it does not
     * answer: `{ jid, name, peers: [{jid, name?, ipAddress?}] }`. Peers are what beo's directory found over mDNS.
     */
    public static function receiver(): ?array
    {
        try {
            $self = Http::timeout(3)->get(config('beolink.api_url').'/api/v1/beolink/self');

            if (!$self->successful()) {
                return null;
            }

            $peers = Http::timeout(3)->get(config('beolink.api_url').'/api/v1/beolink/peers');

            return [
                'jid' => $self->json('jid'),
                'name' => $self->json('friendlyName'),
                'peers' => $peers->successful() ? (array) $peers->json() : [],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Make the receiver leave the source it listens to. */
    public static function leave(): void
    {
        Cache::forget('beolink:host');

        try {
            Http::timeout(5)->post(config('beolink.api_url').'/api/v1/beolink/leave');
        } catch (\Throwable) {
            // The receiver is not running: nothing to leave.
        }
    }
}
