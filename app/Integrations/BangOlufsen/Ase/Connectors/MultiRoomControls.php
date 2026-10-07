<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\MultiRoomId;
use App\Domain\Device\MultiRoomStatus;
use App\Integrations\Common\UnsupportedOperationException;
use App\Integrations\Contracts\SessionHostInterface;
use App\Models\Device;

trait MultiRoomControls
{
    public function multiRoomMetaKey(): string
    {
        return 'ase_jid';
    }

    public function getMultiRoomId(): ?string
    {
        return MultiRoomId::remember($this->device, 'ase_jid', function () {
            $data = $this->getActiveSources();

            return ($data['activeSources']['primaryJid'] ?? '') ?: $this->jidFromProductId();
        });
    }

    /**
     * An idle device reports an empty primaryJid, but its JID is always
     * {typeNumber}.{itemNumber}.{serialNumber}@products.bang-olufsen.com (as listed in BeoZone/System/Products).
     */
    private function jidFromProductId(): ?string
    {
        $id = $this->deviceApiClient()->get('BeoDevice')['beoDevice']['productId'] ?? [];

        if (!isset($id['typeNumber'], $id['itemNumber'], $id['serialNumber'])) {
            return null;
        }

        return "{$id['typeNumber']}.{$id['itemNumber']}.{$id['serialNumber']}@products.bang-olufsen.com";
    }

    public function getJoinablePeerIds(): array
    {
        $data = $this->getActiveSources();
        // The key literally contains a dot — use direct array access, not data_get
        $all = $data['primaryExperience']['listenerList']['_capabilities']['value']['listener.jid'] ?? [];
        $current = $this->getCurrentPeerIds();

        return array_values(array_diff($all, $current));
    }

    /**
     * Idle: primaryJid is empty. Hosting: primaryJid is our own and the listener list holds others besides us.
     * Joined: primaryJid is the host's.
     */
    public function getMultiRoomStatus(): MultiRoomStatus
    {
        $primary = $this->getActiveSources()['activeSources']['primaryJid'] ?? '';
        $own = $this->getMultiRoomId();

        if ($primary === '' || $own === null) {
            return MultiRoomStatus::standalone();
        }

        if ($primary !== $own) {
            return MultiRoomStatus::joined($primary);
        }

        return MultiRoomStatus::hosting(array_values(array_diff($this->getCurrentPeerIds(), [$own])));
    }

    public function getCurrentPeerIds(): array
    {
        $data = $this->getActiveSources();
        $listeners = $data['primaryExperience']['listenerList']['listener'] ?? [];

        // B&O returns a single object when only one listener, not an array
        if (isset($listeners['jid'])) {
            $listeners = [$listeners];
        }

        return array_column($listeners, 'jid');
    }

    public function joinSession(Device $hostDevice): void
    {
        $hostDriver = $hostDevice->driver;

        // The host adds us to its listener list. POSTing the host's JID to our own primaryExperience makes *us*
        // the host of an (empty) experience and drops whatever was playing on the device we meant to join
        // (confirmed on hardware).
        if (!($hostDriver instanceof SessionHostInterface)) {
            throw new UnsupportedOperationException('The device to join cannot take listeners.');
        }

        if ($guestJid = $this->getMultiRoomId()) {
            $hostDriver->addListener($guestJid);
        }
    }

    public function addListener(string $peerId): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/ActiveSources/primaryExperience', [
            'listener' => ['jid' => $peerId],
        ]);
    }

    public function leaveSession(): void
    {
        $this->deviceApiClient()->delete('BeoZone/Zone/ActiveSources/primaryExperience');
    }
}
