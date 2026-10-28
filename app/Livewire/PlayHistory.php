<?php

namespace App\Livewire;

use App\Domain\Catalogue\WantedTracks;
use App\Domain\Library\Normalizer;
use App\Domain\Library\PlayedTrackAdder;
use App\Models\Device;
use App\Models\Media\Track;
use App\Models\Media\WantedTrack;
use App\Models\Play;
use Livewire\Component;
use Livewire\WithPagination;

class PlayHistory extends Component
{
    use WithPagination;

    public ?int $deviceId = null;

    public ?string $sourceFilter = null;

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
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
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
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->deviceId = null;
        $this->sourceFilter = null;
        $this->search = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function render()
    {
        $query = Play::query()
            ->with(['device', 'track' => fn ($q) => $q->withExists('trackFiles'), 'track.artist', 'track.album', 'radioStation'])
            ->orderByDesc('played_at');

        if ($this->deviceId) {
            $query->where('device_id', $this->deviceId);
        }

        if ($this->sourceFilter !== null) {
            $query->where('source_type', $this->sourceFilter);
        }

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('track', function ($tq) use ($search) {
                    $tq->where('name', 'like', "%{$search}%")
                        ->orWhereHas('artist', fn ($aq) => $aq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('album', fn ($aq) => $aq->where('name', 'like', "%{$search}%"));
                })->orWhere('track_name', 'like', "%{$search}%")
                    ->orWhere('artist_name', 'like', "%{$search}%")
                    ->orWhere('album_name', 'like', "%{$search}%")
                    ->orWhere('radio_name', 'like', "%{$search}%")
                    ->orWhere('source_name', 'like', "%{$search}%");
            });
        }

        if ($this->dateFrom !== '') {
            $query->whereDate('played_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate('played_at', '<=', $this->dateTo);
        }

        $plays = $query->paginate(50);

        $devices = Device::orderBy('device_name')->get();

        $sourceTypes = Play::query()
            ->selectRaw('DISTINCT source_type')
            ->whereNotNull('source_type')
            ->orderBy('source_type')
            ->pluck('source_type');

        // Which songs of this page are wished for (one query; the keys decide), for the star.
        $titles = $plays->getCollection()->map(fn (Play $p) => $p->trackTitle())->filter()->map(fn ($t) => Normalizer::track($t))->unique()->all();
        $wished = WantedTrack::whereIn('title_key', $titles)->whereNull('fulfilled_at')->get()
            ->mapWithKeys(fn (WantedTrack $w) => [$w->artist_key.'|'.$w->title_key => true])->all();

        return view('livewire.play-history', [
            'wished' => $wished,
            'plays' => $plays,
            'devices' => $devices,
            'sourceTypes' => $sourceTypes,
        ]);
    }
}
