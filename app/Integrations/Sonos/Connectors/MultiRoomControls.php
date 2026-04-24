<?php

namespace App\Integrations\Sonos\Connectors;

use App\Models\Device;
use duncan3dc\Sonos\Devices\Collection;
use duncan3dc\Sonos\Network;

trait MultiRoomControls
{
    public function multiRoomMetaKey(): string
    {
        return 'sonos_uuid';
    }

    public function getMultiRoomId(): ?string
    {
        return $this->device->meta()->where('key', 'sonos_uuid')->value('value')
            ?? $this->deviceApi->getUuid();
    }

    public function getJoinablePeerIds(): array
    {
        // Sonos does not expose a pre-validated joinable list per-device;
        // the UI falls back to showing all Sonos devices.
        return [];
    }

    public function getCurrentPeerIds(): array
    {
        // Enumerating group members requires a full-network collection;
        // with a single-IP collection only the coordinator is visible.
        return [];
    }

    public function joinSession(Device $hostDevice): void
    {
        // Resolve host UUID via a lightweight single-IP network
        $hostSpeakers = (new Network((new Collection)->addIp($hostDevice->ip_address)))->getSpeakers();
        $hostSpeaker = $hostSpeakers[$hostDevice->ip_address] ?? null;
        if (!$hostSpeaker) {
            return;
        }

        // Resolve joining device's speaker (may not be a coordinator)
        $mySpeakers = (new Network((new Collection)->addIp($this->device->ip_address)))->getSpeakers();
        $mySpeaker = $mySpeakers[$this->device->ip_address] ?? null;
        if (!$mySpeaker) {
            return;
        }

        // Replicate Controller::addSpeaker() at the Speaker level to avoid coordinator requirement
        $mySpeaker->soap('AVTransport', 'SetAVTransportURI', [
            'CurrentURI' => 'x-rincon:'.$hostSpeaker->getUuid(),
            'CurrentURIMetaData' => '',
        ]);
        $mySpeaker->setGroup($mySpeaker->getGroup());
    }

    public function leaveSession(): void
    {
        // Replicate Controller::removeSpeaker() directly on the speaker
        $mySpeakers = (new Network((new Collection)->addIp($this->device->ip_address)))->getSpeakers();
        $mySpeaker = $mySpeakers[$this->device->ip_address] ?? null;
        if ($mySpeaker) {
            $mySpeaker->soap('AVTransport', 'BecomeCoordinatorOfStandaloneGroup');
            $mySpeaker->updateGroup();
        }
    }
}
