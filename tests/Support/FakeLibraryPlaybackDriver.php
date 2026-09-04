<?php

namespace Tests\Support;

use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Models\Device;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

/**
 * Bound as a device's `device_driver` in tests to exercise the
 * LibraryPlaybackInterface contract without touching real Sonos/ASE hardware.
 * Mirrors the filtering/throwing behavior of the real drivers so controller
 * tests observe realistic outcomes.
 */
class FakeLibraryPlaybackDriver implements LibraryPlaybackInterface
{
    /** @var array<int, array{method: string, tracks: Collection<int, Track>}> */
    public static array $calls = [];

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function playLibraryTrack(Track $track): void
    {
        if (!$track->getDlnaUrl()) {
            throw new \RuntimeException("Track {$track->id} has no DLNA URL.");
        }

        self::$calls[] = ['method' => 'playLibraryTrack', 'tracks' => collect([$track])];
    }

    public function playLibraryTracks(Collection $tracks): void
    {
        $playable = $tracks->filter(fn (Track $track) => (bool) $track->getDlnaUrl())->values();

        if ($playable->isEmpty()) {
            throw new \RuntimeException('No tracks with a playable DLNA URL.');
        }

        self::$calls[] = ['method' => 'playLibraryTracks', 'tracks' => $playable];
    }

    public function playLibraryPlaylist(Playlist $playlist): void
    {
        $this->playLibraryTracks($playlist->tracks()->get());
    }
}
