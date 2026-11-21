<?php

namespace App\Livewire;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\ListeningModes;
use App\Domain\Library\SimilarTracks;
use App\Domain\Profile\Profile;
use App\Models\Media\Track;
use App\Support\SelectedDevice;
use Livewire\Component;

/**
 * "Sounds like this": the tracks nearest to one library track by sound (`SimilarTracks`, workstream 4), as compact rows
 * with a play button each and "Play similar" that starts a radio on the player pinned in this browser (the same queue as
 * `POST /api/devices/{id}/library/play-similar`). Shows nothing for a track without a sound embedding.
 *
 * Reusable: the album page's Track details embeds it, and the Discover hub takes it as it is:
 * `<livewire:track-similar :track-id="$id" :limit="12" title="Because you played …" />`.
 * Domain: `App\Domain\Library\SimilarTracks`; docs/frontend/similar-tracks.md.
 */
class TrackSimilar extends Component
{
    public int $trackId;

    /** Rows shown. */
    public int $limit = 8;

    /** The heading above the rows; an empty string leaves it out. */
    public string $title = 'Sounds like this';

    public ?string $notice = null;

    public function mount(int $trackId, int $limit = 8, ?string $title = null): void
    {
        $this->trackId = $trackId;
        $this->limit = max(1, min($limit, 50));
        $this->title = $title ?? $this->title;
    }

    /** Plays one of the rows on the pinned player. */
    public function play(int $trackId): void
    {
        $this->notice = null;
        $device = app(SelectedDevice::class)->device();
        $track = Track::find($trackId);

        if ($device === null) {
            $this->notice = 'Pick a player in the bar below first.';

            return;
        }

        try {
            $track && app(LibraryPlayback::class)->playTrack($device, $track);
        } catch (\Throwable $e) {
            $this->notice = $e->getMessage();
        }
    }

    /** A radio from this track on the pinned player: the track, then what sounds most like it. */
    public function radio(): void
    {
        $this->notice = null;
        $device = app(SelectedDevice::class)->device();

        if ($device === null) {
            $this->notice = 'Pick a player in the bar below first.';

            return;
        }

        $tracks = $this->similar($device)->radio();
        if ($tracks->count() <= ListeningModes::MIN_TRACKS) {
            $this->notice = 'Not enough similar tracks this player can play.';

            return;
        }

        try {
            app(LibraryPlayback::class)->playTracks($device, $tracks, $tracks->first());
        } catch (\Throwable $e) {
            $this->notice = $e->getMessage();
        }
    }

    private function similar(?\App\Models\Device $device = null): SimilarTracks
    {
        return new SimilarTracks(Track::findOrFail($this->trackId), LibrarySources::hiddenForRequest(null), $device);
    }

    /** Lazy where it sits in a long page: nothing is searched for panels nobody opens. */
    public function placeholder(): string
    {
        return '<div class="text-gray-500 dark:text-gray-400">…</div>';
    }

    public function render()
    {
        $rows = [];
        $analysed = false;

        if (Profile::allows('analysis')) {
            $similar = $this->similar();
            $analysed = $similar->source() !== null;
            $matches = $analysed ? $similar->matches()->take($this->limit) : collect();
            $tracks = Track::query()->with(['artist', 'album'])->findMany($matches->pluck('track_id'))->keyBy('id');

            foreach ($matches as $match) {
                if (!$track = $tracks->get($match['track_id'])) {
                    continue;
                }
                $cover = LibraryItemArtwork::forImages($track->images ?: $track->album?->images);
                $rows[] = [
                    'id' => $track->id, 'title' => $track->name, 'artist' => $track->artist?->name,
                    'image' => $cover['proxy_120'] ?? null, 'colors' => $cover['colors'] ?? null,
                    'similarity' => (int) round($match['similarity'] * 100),
                ];
            }
        }

        return view('livewire.track-similar', ['rows' => $rows, 'analysed' => $analysed]);
    }
}
