<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Integrations\Contracts\MultiRoomInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;

trait MultiRoomControls
{
    public function multiRoomMetaKey(): string
    {
        return 'ase_jid';
    }

    public function getMultiRoomId(): ?string
    {
        $cacheKey = "device:{$this->device->id}:ase_jid";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $data = $this->getActiveSources();
        $jid = ($data['activeSources']['primaryJid'] ?? '') ?: $this->jidFromProductId();

        if ($jid) {
            Cache::put($cacheKey, $jid, 86400 * 7);
            $this->device->meta()->updateOrCreate(
                ['key' => 'ase_jid'],
                ['value' => $jid]
            );
        }

        return $jid;
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

        // ASE joins by the host adding this device to its listener list: POSTing the host's JID to our own
        // primaryExperience makes *us* the host of an (empty) experience and drops whatever was playing
        // on the device we meant to join (confirmed on hardware).
        if (method_exists($hostDriver, 'deviceApiClient')) {
            $guestJid = $this->getMultiRoomId();

            if (!$guestJid) {
                return;
            }

            $hostDriver->deviceApiClient()->post('BeoZone/Zone/ActiveSources/primaryExperience', [
                'listener' => ['jid' => $guestJid],
            ]);

            return;
        }

        // Host on another platform (Mozart): we can only point ourselves at its JID. Unverified on hardware.
        $hostJid = $hostDevice->meta()->where('key', 'mozart_jid')->value('value');

        if (!$hostJid && $hostDriver instanceof MultiRoomInterface) {
            $hostJid = $hostDriver->getMultiRoomId();
        }

        if (!$hostJid) {
            return;
        }

        $this->deviceApiClient()->post('BeoZone/Zone/ActiveSources/primaryExperience', [
            'listener' => ['jid' => $hostJid],
        ]);
    }

    public function leaveSession(): void
    {
        $this->deviceApiClient()->delete('BeoZone/Zone/ActiveSources/primaryExperience');
    }
}
