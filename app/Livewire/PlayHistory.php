<?php

namespace App\Livewire;

use App\Domain\Catalogue\WantedTracks;
use App\Domain\Library\Normalizer;
use App\Domain\Library\PlayedTrackAdder;
use App\Domain\Stats\PlayHistory as PlayLog;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\Media\WantedTrack;
use App\Models\Play;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class PlayHistory extends Component
{
    #[Url(as: 'device')]
    public ?int $deviceId = null;

    #[Url(as: 'source')]
    public ?string $sourceFilter = null;

    /** `library` (plays of a track in the library) or `unknown` (logged as text, or a radio stub); null = all. */
    #[Url]
    public ?string $match = null;

    /** Only the plays of this album or artist (the links on those pages). */
    #[Url]
    public ?int $albumId = null;

    #[Url]
    public ?int $artistId = null;

    /** Keyset cursor of the page shown (null = newest) and the cursors of the pages before it. */
    public ?string $cursor = null;

    /** @var list<string|null> */
    public array $previousCursors = [];

    public ?string $nextCursor = null;

    public string $search = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /** The play being matched to a library track (modal), with the search it is matched by. */
    public ?int $matchPlayId = null;

    public string $matchName = '';

    public string $matchArtist = '';

    /** @var list<array> */
    public array $matchLocal = [];

    public ?string $matchError = null;

    public function updatedDeviceId(): void
    {
        $this->resetPaging();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPaging();
    }

    public function updatedSearch(): void
    {
        $this->resetPaging();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPaging();
    }

    public function updatedDateTo(): void
    {
        $this->resetPaging();
    }

    public function updatedMatch(): void
    {
        $this->match = $this->match === '' ? null : $this->match;
        $this->resetPaging();
    }

    private function resetPaging(): void
    {
        $this->cursor = null;
        $this->previousCursors = [];
    }

    /** The next (older) page: the same keyset cursor the API hands out. */
    public function nextPage(?string $cursor = null): void
    {
        $cursor ??= $this->nextCursor;

        if ($cursor === null) {
            return;
        }

        $this->previousCursors[] = $this->cursor;
        $this->cursor = $cursor;
    }

    public function previousPage(): void
    {
        if ($this->previousCursors === []) {
            return;
        }

        $this->cursor = array_pop($this->previousCursors);
    }

    /** Opens the match modal for a play that was logged as text, searched by its name and artist. */
    public function openMatch(int $playId): void
    {
        $play = Play::find($playId);

        $stub = $play?->track?->isRadioStub() ? $play->track : null;

        if ($play === null || ($play->track_id !== null && $stub === null) || ($stub === null && $play->track_name === null)) {
            return;
        }

        $this->matchPlayId = $play->id;
        $this->matchName = $stub?->name ?? $play->track_name;
        $this->matchArtist = $stub?->artist?->name ?? $play->artist_name ?? '';
        $this->searchMatch();
    }

    public function closeMatch(): void
    {
        $this->reset('matchPlayId', 'matchName', 'matchArtist', 'matchLocal', 'matchError');
    }

    public function updatedMatchName(): void
    {
        $this->searchMatch();
    }

    public function updatedMatchArtist(): void
    {
        $this->searchMatch();
    }

    public function searchMatch(): void
    {
        $name = trim($this->matchName);
        $artist = trim($this->matchArtist);
        $this->matchError = null;
        $this->matchLocal = [];

        if ($name === '') {
            return;
        }

        $this->matchLocal = Track::query()
            ->where('name', 'like', "%{$name}%")
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'radio'))
            ->when($artist !== '', fn ($q) => $q->whereHas('artist', fn ($a) => $a->where('name', 'like', "%{$artist}%")))
            ->with(['artist', 'album'])
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Track $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'artist' => $t->artist?->name,
                'album' => $t->album?->name,
                'source' => $t->source,
            ])->all();
    }

    public function chooseLocal(int $trackId): void
    {
        $track = Track::find($trackId);

        if ($track !== null) {
            $this->link($track);
        }
    }

    /** Points the play, and the other plays logged as text for the same track, at the library track. */
    private function link(Track $track): void
    {
        $play = Play::find($this->matchPlayId);

        if ($play?->track?->isRadioStub()) {
            // The stub is replaced by the chosen track: all its plays move over.
            PlayedTrackAdder::replaceStub($play->track, $track);
        } elseif ($play !== null) {
            $play->update(['track_id' => $track->id]);
            PlayedTrackAdder::linkPlays($track, (string) $play->track_name, (string) $play->artist_name);
        }

        $this->closeMatch();
    }

    /** The heart of a history row: favorites the song, putting it in the library first when it is only logged as text or a radio stub. */
    public function favorite(int $playId): void
    {
        $play = Play::find($playId);
        $track = $play ? PlayedTrackAdder::addFromPlay($play) : null;

        $track?->toggleFavorite();
    }

    /** The star of a history row: wish for the song (something to buy or rip), or take it off the wishlist. */
    public function want(int $playId): void
    {
        $play = Play::with(['track.artist', 'track.album'])->find($playId);
        $artist = $play?->artistTitle();
        $title = $play?->trackTitle();

        if ($artist === null || $title === null) {
            return;
        }

        if (WantedTracks::isWished($artist, $title)) {
            WantedTrack::where('artist_key', Normalizer::artist($artist))->where('title_key', Normalizer::track($title))->get()->each(fn ($s) => WantedTracks::unwish($s));

            return;
        }

        WantedTracks::wish($artist, $title, $play->track?->album?->name ?? $play->album_name, $play->image_url, $play->radioStation || $play->radio_name || $play->track?->isRadioStub() ? 'radio' : 'history');
    }

    public function setSource(?string $source): void
    {
        $this->sourceFilter = $source;
        $this->resetPaging();
    }

    public function clearFilters(): void
    {
        $this->deviceId = null;
        $this->sourceFilter = null;
        $this->search = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->match = null;
        $this->albumId = null;
        $this->artistId = null;
        $this->resetPaging();
    }

    public function render()
    {
        $query = PlayLog::filtered($this->dateFrom ?: null, $this->dateTo ?: null, $this->search, null);

        if ($this->deviceId) {
            PlayLog::onDevice($query, $this->deviceId);
        }
        if ($this->sourceFilter !== null) {
            PlayLog::ofSourceType($query, $this->sourceFilter);
        }
        if (in_array($this->match, [PlayLog::MATCH_LIBRARY, PlayLog::MATCH_UNKNOWN], true)) {
            PlayLog::ofMatch($query, $this->match);
        }
        if ($this->albumId) {
            PlayLog::ofAlbum($query, $this->albumId);
        }
        if ($this->artistId) {
            PlayLog::ofArtist($query, $this->artistId);
        }

        try {
            ['plays' => $plays, 'next_cursor' => $next] = PlayLog::fetch($query, $this->cursor);
        } catch (ValidationException) {
            // A stale or hand-edited cursor: start again from the newest.
            $this->resetPaging();
            ['plays' => $plays, 'next_cursor' => $next] = PlayLog::fetch($query, null);
        }
        $this->nextCursor = $next;

        $devices = Device::orderBy('device_name')->get();

        $sourceTypes = Play::query()
            ->selectRaw('DISTINCT source_type')
            ->whereNotNull('source_type')
            ->orderBy('source_type')
            ->pluck('source_type');

        // Which songs of this page are wished for (one query; the keys decide), for the star.
        $titles = $plays->map(fn (Play $p) => $p->trackTitle())->filter()->map(fn ($t) => Normalizer::track($t))->unique()->all();
        $wished = WantedTrack::whereIn('title_key', $titles)->whereNull('fulfilled_at')->get()
            ->mapWithKeys(fn (WantedTrack $w) => [$w->artist_key.'|'.$w->title_key => true])->all();

        return view('livewire.play-history', [
            'wished' => $wished,
            'plays' => $plays,
            'devices' => $devices,
            'sourceTypes' => $sourceTypes,
            'album' => $this->albumId ? Album::with('artist')->find($this->albumId) : null,
            'artist' => $this->artistId ? Artist::find($this->artistId) : null,
        ]);
    }
}
