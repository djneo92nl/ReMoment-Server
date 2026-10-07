<?php

namespace App\Domain\Device;

use App\Models\Device;

/**
 * Where a device stands in a multiroom session: on its own, hosting others, or joined to a host.
 * Holds platform peer IDs (JIDs); resolve() turns them into devices for the API, MQTT and the web card.
 */
final readonly class MultiRoomStatus
{
    public const STANDALONE = 'standalone';

    public const HOST = 'host';

    public const LISTENER = 'listener';

    /** @param string[] $listenerPeerIds the other devices listening to this one (never itself) */
    public function __construct(
        public string $role = self::STANDALONE,
        public ?string $hostPeerId = null,
        public array $listenerPeerIds = [],
    ) {}

    public static function standalone(): self
    {
        return new self;
    }

    public static function hosting(array $listenerPeerIds): self
    {
        return $listenerPeerIds === [] ? self::standalone() : new self(self::HOST, null, array_values($listenerPeerIds));
    }

    public static function joined(string $hostPeerId): self
    {
        return new self(self::LISTENER, $hostPeerId);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['role'] ?? self::STANDALONE),
            $data['host_peer_id'] ?? null,
            array_values($data['listener_peer_ids'] ?? []),
        );
    }

    /** @return array{role: string, host_peer_id: ?string, listener_peer_ids: string[]} */
    public function toArray(): array
    {
        return ['role' => $this->role, 'host_peer_id' => $this->hostPeerId, 'listener_peer_ids' => $this->listenerPeerIds];
    }

    /**
     * The shape clients see: `host` (the device this one listens to) and `listeners` (devices listening to
     * it), as {id, name}. A peer that isn't a known device is left out.
     *
     * @return array{role: string, host: ?array{id: int, name: string}, listeners: array<int, array{id: int, name: string}>}
     */
    public function resolve(Device $device): array
    {
        $summary = fn (Device $d) => ['id' => $d->id, 'name' => $d->device_name];

        $host = $this->hostPeerId === null
            ? null
            : MultiRoomPeers::devices([$this->hostPeerId], $device)->first();

        return [
            'role' => $this->role,
            'host' => $host ? $summary($host) : null,
            'listeners' => MultiRoomPeers::devices($this->listenerPeerIds, $device)->map($summary)->values()->all(),
        ];
    }
}
