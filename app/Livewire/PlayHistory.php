<?php

namespace App\Livewire;

use App\Domain\Library\PlayedTrackAdder;
use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Models\Device;
use App\Models\Media\Track;
use App\Models\Play;
use App\Services\SpotifyTokenService;
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

    /** @var list<array> */
    public array $matchSpotify = [];

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

        if ($play === null || $play->track_id !== null || $play->track_name === null) {
            return;
        }

        $this->matchPlayId = $play->id;
        $this->matchName = $play->track_name;
        $this->matchArtist = $play->artist_name ?? '';
        $this->searchMatch();
    }

    public function closeMatch(): void
    {
        $this->reset('matchPlayId', 'matchName', 'matchArtist', 'matchLocal', 'matchSpotify', 'matchError');
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
        $this->matchSpotify = [];

        if ($name === '') {
            return;
        }

        $this->matchLocal = Track::query()
            ->where('name', 'like', "%{$name}%")
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

        $spotify = app(SpotifyTokenService::class);
        if (!$spotify->isConnected()) {
            return;
        }

        try {
            $results = $spotify->makeApiClient()->search(trim($name.' '.$artist), 'track', ['limit' => 8]);
        } catch (\Throwable) {
            $this->matchError = 'Spotify could not be reached.';

            return;
        }

        $this->matchSpotify = array_map(fn ($t) => [
            'id' => $t['id'],
            'name' => $t['name'],
            'artist' => $t['artists'][0]['name'] ?? null,
            'album' => $t['album']['name'] ?? null,
        ], $results['tracks']['items'] ?? []);
    }

    public function chooseLocal(int $trackId): void
    {
        $track = Track::find($trackId);

        if ($track !== null) {
            $this->link($track);
        }
    }

    /** Imports the Spotify track into the library, then links the play to it. */
    public function chooseSpotify(string $spotifyTrackId, SpotifyLibraryImporter $importer): void
    {
        try {
            $this->link($importer->importTrackById($spotifyTrackId));
        } catch (\Throwable $e) {
            $this->matchError = "Could not add the track from Spotify: {$e->getMessage()}";
        }
    }

    /** Points the play, and the other plays logged as text for the same track, at the library track. */
    private function link(Track $track): void
    {
        $play = Play::find($this->matchPlayId);

        if ($play !== null) {
            $play->update(['track_id' => $track->id]);
            PlayedTrackAdder::linkPlays($track, (string) $play->track_name, (string) $play->artist_name);
        }

        $this->closeMatch();
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
            ->with(['device', 'track.artist', 'track.album', 'radioStation'])
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

        return view('livewire.play-history', [
            'plays' => $plays,
            'devices' => $devices,
            'sourceTypes' => $sourceTypes,
        ]);
    }
}
