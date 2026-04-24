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
        $jid = $data['activeSources']['primaryJid'] ?? null;

        if ($jid) {
            Cache::put($cacheKey, $jid, 86400 * 7);
            $this->device->meta()->updateOrCreate(
                ['key' => 'ase_jid'],
                ['value' => $jid]
            );
        }

        return $jid;
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
        $hostJid = $hostDevice->meta()->where('key', 'ase_jid')->value('value');

        if (!$hostJid && $hostDevice->driver instanceof MultiRoomInterface) {
            $hostJid = $hostDevice->driver->getMultiRoomId();
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
