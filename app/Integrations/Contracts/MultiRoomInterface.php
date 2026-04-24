<?php

namespace App\Integrations\Contracts;

use App\Models\Device;

interface MultiRoomInterface
{
    /** The device_meta key used to store this platform's multiroom ID (e.g. 'ase_jid', 'sonos_uuid') */
    public function multiRoomMetaKey(): string;

    /** Platform-specific identifier for this device (B&O JID, Sonos UUID) */
    public function getMultiRoomId(): ?string;

    /** Platform IDs of devices that CAN join this session (empty = show all same-brand) */
    public function getJoinablePeerIds(): array;

    /** Platform IDs currently in session with this device */
    public function getCurrentPeerIds(): array;

    /** Make this device join a session hosted by $hostDevice */
    public function joinSession(Device $hostDevice): void;

    /** Make this device leave the current multiroom session */
    public function leaveSession(): void;
}
