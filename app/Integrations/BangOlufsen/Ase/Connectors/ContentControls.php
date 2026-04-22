<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

trait ContentControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function playDlnaTrack(string $url, bool $instant = true): void
    {
        $path = 'BeoZone/Zone/PlayQueue'.($instant ? '?instantplay' : '');
        $this->deviceApiClient()->post($path, [
            'playQueueItem' => [
                'behaviour' => 'impulsive',
                'track' => [
                    'dlna' => ['url' => $url],
                ],
            ],
        ]);
    }

    public function playBeoRadioStation(string $contentId): void
    {
        $sources = $this->deviceApiClient()->get('BeoZone/Zone/Sources');
        $sourceId = null;

        foreach ($sources['sources'] ?? [] as $pair) {
            if (str_starts_with($pair[0], 'beoradio:')) {
                $sourceId = $pair[0];
                break;
            }
        }

        if ($sourceId === null) {
            throw new \RuntimeException('No B&O Radio source found on this device.');
        }

        $this->deviceApiClient()->post('BeoZone/Zone/ActiveSources', [
            'primaryExperience' => [
                'source' => ['id' => $sourceId],
            ],
            'contentId' => $contentId,
        ]);
    }

    public function playTuneInStation(string $stationId, bool $instant = true): void
    {
        $path = 'BeoZone/Zone/PlayQueue'.($instant ? '?instantplay' : '');
        $this->deviceApiClient()->post($path, [
            'playQueueItem' => [
                'behaviour' => 'impulsive',
                'station' => [
                    'tuneIn' => ['stationId' => $stationId],
                ],
            ],
        ]);
    }

    public function playDeezerTrack(int $trackId, bool $instant = true): void
    {
        $path = 'BeoZone/Zone/PlayQueue'.($instant ? '?instantplay' : '');
        $this->deviceApiClient()->post($path, [
            'playQueueItem' => [
                'behaviour' => 'impulsive',
                'track' => [
                    'deezer' => ['id' => $trackId],
                ],
            ],
        ]);
    }

    public function playDeezerPlaylist(int $playlistId, bool $instant = true): void
    {
        $path = 'BeoZone/Zone/PlayQueue'.($instant ? '?instantplay' : '');
        $this->deviceApiClient()->post($path, [
            'playQueueItem' => [
                'behaviour' => 'impulsive',
                'playList' => [
                    'deezer' => ['id' => $playlistId],
                ],
            ],
        ]);
    }

    public function getMyButtons(): array
    {
        return $this->deviceApiClient()->get('BeoZone/Zone/Snapshot/');
    }

    public function activateMyButton(string $buttonId): void
    {
        $this->deviceApiClient()->put("BeoZone/Zone/Snapshot/Activate/{$buttonId}", []);
    }
}
