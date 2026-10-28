<?php

namespace App\Livewire;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Availability\ExternalCatalogues;
use App\Domain\Catalogue\WantedTracks;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\Normalizer;
use App\Livewire\Concerns\PlaysExternal;
use App\Livewire\Concerns\WantsStreamingTracks;
use App\Models\Media\Track;
use App\Models\Play;
use App\Support\SelectedDevice;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * "Recently played" of the library page, as compact rows (artwork, track, artist; like the radio stations). The tracks come from the
 * play history, limited to the scope. Only the Streaming scope also merges in what the account played on Spotify (read live), newest
 * first, one row per song; Local (and All) never mix it in. A library track can be favorited; a Spotify track can only be wanted.
 */
class RecentTracks extends Component
{
    use PlaysExternal, WantsStreamingTracks;

    public string $scope = 'local';

    /** Rows shown before "Show all". */
    public int $limit = 12;

    public function showAll(): void
    {
        $this->limit = PHP_INT_MAX;
    }

    /** Plays a library track on the player pinned in this browser. */
    public function playLibrary(int $trackId): void
    {
        $this->notice = null;
        $device = app(SelectedDevice::class)->device();
        $track = Track::find($trackId);

        if ($device === null || $track === null) {
            $this->notice = $device === null ? 'Pick a player in the bar below first.' : null;

            return;
        }

        try {
            app(LibraryPlayback::class)->playTrack($device, $track);
        } catch (\Throwable $e) {
            $this->notice = $e->getMessage();
        }
    }

    public function favorite(int $trackId): void
    {
        Track::find($trackId)?->toggleFavorite();
    }

    public function render()
    {
        $rows = $this->libraryRows();

        if (LeadingSource::group($this->scope) === 'streaming') {
            $rows = $this->withSpotify($rows);
        }

        $wished = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'spotify') {
                $wished[$row['id']] = WantedTracks::isWished($row['artist'], $row['title']);
            }
        }

        return view('livewire.recent-tracks', [
            'rows' => $rows,
            'wished' => $wished,
            'canWant' => $wished !== [] || true,
            'title' => 'Recently played',
        ]);
    }

    /** @return list<array<string, mixed>> one row per library track, newest play first, within the scope */
    private function libraryRows(): array
    {
        $plays = Play::query()->whereNotNull('track_id')->orderByDesc('played_at')->limit(120)->get(['track_id', 'played_at']);
        $latest = [];
        foreach ($plays as $play) {
            $latest[$play->track_id] ??= $play->played_at;
        }
        if ($latest === []) {
            return [];
        }

        $tracks = LibrarySources::tracks(Track::query()->with(['artist', 'album'])->whereIn('tracks.id', array_keys($latest))->where(fn ($q) => $q->whereNull('tracks.source')->orWhere('tracks.source', '!=', 'radio')), LeadingSource::hidden($this->scope))->get()->keyBy('id');

        $rows = [];
        foreach ($latest as $id => $at) {
            if (!$track = $tracks->get($id)) {
                continue;
            }
            $cover = LibraryItemArtwork::forImages($track->images ?: $track->album?->images);
            $rows[] = [
                'kind' => 'library', 'id' => $track->id, 'title' => $track->name, 'artist' => $track->artist?->name, 'at' => $at,
                'image' => $cover['proxy_120'] ?? null, 'colors' => $cover['colors'] ?? null, 'source' => $track->sources()[0] ?? null,
                'favorite' => $track->favorited_at !== null, 'key' => Normalizer::artist($track->artist?->name).'|'.Normalizer::track($track->name),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withSpotify(array $rows): array
    {
        $catalogue = ExternalCatalogues::for('spotify');
        if (!$catalogue?->enabled()) {
            return $rows;
        }

        try {
            foreach ($catalogue->account('recent') as $item) {
                $rows[] = [
                    'kind' => 'spotify', 'id' => $item->id, 'title' => $item->title, 'artist' => $item->artist,
                    'at' => $item->playedAt ? Carbon::parse($item->playedAt) : now()->subYears(10), 'image' => $item->image, 'colors' => null, 'source' => 'spotify',
                    'libraryId' => $item->libraryId, 'key' => Normalizer::artist($item->artist).'|'.Normalizer::track($item->title),
                ];
            }
        } catch (\Throwable) {
            // Spotify down or the scope missing: the library's own plays still show.
            return $rows;
        }

        usort($rows, fn ($a, $b) => $b['at'] <=> $a['at']);

        // One row per song, whichever side played it last.
        $seen = [];

        return array_values(array_filter($rows, function ($row) use (&$seen) {
            return !isset($seen[$row['key']]) && ($seen[$row['key']] = true);
        }));
    }
}
