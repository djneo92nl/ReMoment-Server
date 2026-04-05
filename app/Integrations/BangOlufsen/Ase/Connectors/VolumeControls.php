<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\Cache\Volume;

trait VolumeControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function setVolume(int $volume): int
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Volume/Speaker/Level', ['level' => $volume]);

        return $this->getVolume();
    }

    public function getVolume(): int
    {
        $volume = Volume::getVolume($this->device->id);
        if ($volume === false) {
            $response = $this->deviceApiClient()->get('BeoZone/Zone/Sound/Volume/Speaker/Level');
            if (array_key_exists('level', $response)) {
                Volume::updateVolume($this->device->id, (int) $response['level']);

                return (int) $response['level'];
            }

            return 0;
        }

        return (int) $volume;
    }

    public function setMaxVolume(int $minimum, int $maximum): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Volume/Speaker/Range', [
            'range' => ['minimum' => $minimum, 'maximum' => $maximum],
        ]);
    }

    public function incrementVolume(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Sound/Volume/Speaker/ContinuousLevelAction', [
            'continuousLevelAction' => 'continuousUp',
            'continuousLevelTimeoutAction' => 0,
        ]);
    }

    public function decrementVolume(): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/Sound/Volume/Speaker/ContinuousLevelAction', [
            'continuousLevelAction' => 'continuousDown',
            'continuousLevelTimeoutAction' => 0,
        ]);
    }

    public function getMuted(): bool
    {
        $response = $this->deviceApiClient()->get('BeoZone/Zone/Sound/Volume/Speaker/Muted');

        return (bool) ($response['muted'] ?? false);
    }

    public function mute(): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Volume/Speaker/Muted', ['muted' => true]);
    }

    public function unmute(): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Volume/Speaker/Muted', ['muted' => false]);
    }

    public function getSpeakerGroups(): array
    {
        return $this->deviceApiClient()->get('BeoZone/Zone/Sound/SpeakerGroup');
    }

    public function setSpeakerGroup(int $groupId): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/SpeakerGroup/Active', ['active' => $groupId]);
    }

    public function getSoundModes(): array
    {
        return $this->deviceApiClient()->get('BeoZone/Zone/Sound/Mode');
    }

    public function setSoundMode(int $modeId): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Mode/Active', ['active' => $modeId]);
    }
}
