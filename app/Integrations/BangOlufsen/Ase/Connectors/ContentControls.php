<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Storage\StreamUrl;
use App\Models\Media\Track;

trait ContentControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    /**
     * Starts or queues a stream. For a track of our own files the item also carries its name, number and
     * cover, because the speaker keeps nothing else to show; a speaker that rejects those fields gets the plain
     * item, which is what DLNA servers' tracks always use.
     */
    public function playDlnaTrack(string $url, bool $instant = true, ?Track $track = null): void
    {
        $path = 'BeoZone/Zone/PlayQueue'.($instant ? '?instantplay' : '');
        $item = ['behaviour' => 'impulsive', 'track' => ['dlna' => ['url' => $url]]];

        if ($track !== null && $track->isOwnFile()) {
            try {
                $rich = $item;
                $rich['track'] += $this->ownTrackDetails($track);
                $this->deviceApiClient()->postStrict($path, ['playQueueItem' => $rich]);

                return;
            } catch (\RuntimeException $e) {
                // fall through to the plain item
                \Illuminate\Support\Facades\Log::warning('ASE rejected the track details of a queue item, sending it plain: '.$e->getMessage());
            }
        }

        $this->deviceApiClient()->post($path, ['playQueueItem' => $item]);
    }

    /**
     * What the speaker keeps of a streamed track's own item: name, track number and the cover as `image` entries.
     * No `duration`: it is kept but the B&O app then shows it as seconds (a 190 s track as -3166:38); the speaker reads
     * the real length from the stream. Probed on a BeoSound Essence MK2: it drops any other field inside `dlna`,
     * and answers 400 ("Illegal structure ... Key: dlna") to `artist` and `parentAlbum`, whose objects
     * need provider ids a DLNA stream has none of. Title, artist and album come from the file's tags when it plays.
     *
     * @return array<string, mixed>
     */
    private function ownTrackDetails(Track $track): array
    {
        $track->loadMissing('album');
        $cover = LibraryArtwork::coverUrl($track->images) ?? LibraryArtwork::coverUrl($track->album?->images);
        $url = $cover ? StreamUrl::storage((string) (ArtworkCache::get($cover)['proxy_512'] ?? '')) : null;

        return array_filter([
            'name' => $track->name,
            'trackNumber' => $track->metaInt('track_number'),
            'image' => $url ? [['url' => $url, 'size' => 'large', 'mediatype' => 'image/jpg']] : null,
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
