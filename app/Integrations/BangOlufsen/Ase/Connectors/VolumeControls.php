<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

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
        return $this->deviceApiClient()->get('BeoZone/Zone/Speaker/Level')['level'];
    }

    public function incrementVolume(): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Volume/Speaker/ContinuousLevelAction',
            [
                'continuousLevelAction' => 'continuousUp',
                'continuousLevelTimeoutAction' => 0,

            ]);
    }

    public function decrementVolume(): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Volume/Speaker/ContinuousLevelAction',
            [
                'continuousLevelAction' => 'continuousDown',
                'continuousLevelTimeoutAction' => 0,

            ]);
    }

    public function mute(): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Volume/Speaker/Muted', ['muted' => true]);
    }

    public function unmute(): void
    {
        $this->deviceApiClient()->put('BeoZone/Zone/Sound/Volume/Speaker/Muted', ['muted' => true]);
    }
}
