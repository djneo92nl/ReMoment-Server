<?php

namespace App\Livewire\Concerns;

use App\Domain\Device\DeviceCache;
use App\Domain\Library\PlayedTrackAdder;
use App\Domain\Media\NowPlaying;
use App\Models\Device;
use App\Models\Media\Track;

/**
 * Extras for the playing track on the single-device page: its stored lyrics and an
 * "Add to library" button for a track the library lacks. The component provides the device.
 */
trait ManagesTrackExtras
{
    public ?string $currentTrackId = null;

    public ?string $lyricsPlain = null;

    public ?string $lyricsSynced = null;

    /** Set once the playing track was added from here: the page to open it on. */
    public ?string $addedUrl = null;

    public ?string $addFailure = null;

    abstract protected function trackExtrasDevice(): Device;

    /** Reloads the lyrics when the track changed; true when the playing track can be added to the library. */
    protected function refreshTrackExtras(?NowPlaying $nowPlaying): bool
    {
        $signature = ($nowPlaying?->track?->id ?? '').'|'.($nowPlaying?->track?->name ?? '');

        if ($signature !== $this->currentTrackId) {
            $this->currentTrackId = $signature;
            $this->lyricsPlain = null;
            $this->lyricsSynced = null;
            $this->addedUrl = null;
            $this->addFailure = null;

            $track = $this->resolveLyricsTrack($nowPlaying);
            if ($track) {
                $this->lyricsPlain = $track->lyricsPlain();
                $this->lyricsSynced = $track->lyricsSynced();
            }
        }

        // A playing track the library doesn't have (an external service, radio with a title) can be added.
        return $this->addedUrl === null
            && $nowPlaying?->track !== null
            && !PlayedTrackAdder::inLibrary($nowPlaying);
    }

    public function addToLibrary(): void
    {
        $nowPlaying = DeviceCache::getNowPlaying($this->trackExtrasDevice()->id);
        $track = $nowPlaying ? PlayedTrackAdder::add($nowPlaying) : null;

        if ($track === null) {
            $this->addFailure = 'Nothing to add: this track has no artist or name.';

            return;
        }

        $this->addFailure = null;
        $this->addedUrl = $track->album_id ? route('albums.show', $track->album_id).'#track-'.$track->id : route('artists.show', $track->artist_id);
    }

    private function resolveLyricsTrack(?NowPlaying $nowPlaying): ?Track
    {
        if ($nowPlaying === null) {
            return null;
        }

        $externalId = $nowPlaying->track?->id;
        $name = $nowPlaying->track?->name;
        $artist = $nowPlaying->track?->artist?->name;

        $withLyrics = fn ($q) => $q->whereIn('key', ['lyrics_plain', 'lyrics_synced']);

        if ($externalId) {
            $track = Track::where('external_id', $externalId)->with(['metadata' => $withLyrics])->first();
            if ($track) {
                return $track;
            }
        }

        if ($name && $artist) {
            return Track::where('name', $name)
                ->whereHas('artist', fn ($q) => $q->where('name', $artist))
                ->whereHas('metadata', fn ($q) => $q->whereIn('key', ['lyrics_plain', 'lyrics_synced'])->where('value', '!=', ''))
                ->with(['metadata' => $withLyrics])
                ->first();
        }

        return null;
    }
}
