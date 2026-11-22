<?php

namespace App\Domain\Library;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Similar\Embeddings;
use App\Domain\Similar\SqliteVecIndex;
use App\Domain\Similar\VectorIndex;
use App\Domain\Similar\VectorScan;
use App\Models\Device;
use App\Models\FileAnalysis;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Similar tracks" (workstream 4, docs/architecture/similar-tracks.md): the tracks of the scope whose sound embedding
 * (Discogs-EffNet, one vector per own file, `file_embeddings`) is nearest to this track's, by cosine similarity.
 *
 * Only own files that were analysed with the ML sidecar have one, so only they can be the seed or a result. A candidate
 * is never the track itself, the same AcoustID recording, or a track of the same artist with the same normalised title
 * (the same song on another album or source), as for `MixesWith`. The scope, the client's hidden libraries and the
 * device's playability narrow the candidates before anything is compared.
 *
 * The search is the exact scan of `VectorScan` unless `similar.engine` is `sqlite-vec`, the library loads and its file
 * matches the table (same number of rows): then the nearest rows of the whole library are asked of it, narrowed by the
 * scope, and the scan only runs when that leaves too few. At most `similar.max_results` neighbours are kept and the
 * answer is reused for `similar.cache_seconds` unless an embedding was written meanwhile.
 */
final class SimilarTracks
{
    /** @param  list<string>  $hidden  sources hidden by scope and client (LibrarySources::hiddenForRequest) */
    public function __construct(
        private readonly Track $track,
        private readonly array $hidden = [],
        private readonly ?Device $device = null,
        private readonly ?VectorIndex $index = null,
    ) {}

    /** The seed's packed vector, null while its primary file has no (current) embedding. */
    private function vector(): ?string
    {
        return Embeddings::ofTrack($this->track->id);
    }

    /** What the answer is made from, null when the track has no embedding yet. @return array{model: string, dimensions: int}|null */
    public function source(): ?array
    {
        return $this->vector() === null ? null : ['model' => Embeddings::MODEL, 'dimensions' => Embeddings::DIMENSIONS];
    }

    /** The tracks that may be a result, with the embedding joined in. @return Builder<Track> */
    private function candidates(): Builder
    {
        $query = LibrarySources::tracks(Track::query(), $this->hidden)
            ->join('track_files', fn ($j) => $j->on('track_files.track_id', '=', 'tracks.id')->where('track_files.is_primary', true))
            ->join('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
            ->join('file_embeddings', 'file_embeddings.file_id', '=', 'track_files.file_id')
            ->where('file_embeddings.model', Embeddings::MODEL)
            ->where(fn ($q) => $q->whereNull('file_embeddings.content_hash')->orWhereColumn('file_embeddings.content_hash', 'file_analyses.content_hash'))
            ->where('tracks.id', '!=', $this->track->id)
            ->when($this->track->artist_id !== null && $this->track->name_key !== null, fn (Builder $q) => $q->whereNot(
                fn ($same) => $same->where('tracks.artist_id', $this->track->artist_id)->where('tracks.name_key', $this->track->name_key)
            ));

        $recording = $this->track->analysis()->value('acoustid_recording_mbid');
        if ($recording) {
            $query->where(fn ($q) => $q->whereNull('file_analyses.acoustid_recording_mbid')->orWhere('file_analyses.acoustid_recording_mbid', '!=', $recording));
        }

        if ($this->device !== null) {
            LibraryPlayback::playableTracks($query, $this->device);
        }

        return $query;
    }

    /**
     * The neighbours, most similar first: `[track_id, similarity, distance]` (cosine, distance is 1 minus it), at most
     * `similar.max_results`. Empty when the track has no embedding.
     *
     * @return Collection<int, array{track_id: int, similarity: float, distance: float}>
     */
    public function matches(): Collection
    {
        $vector = $this->vector();
        if ($vector === null) {
            return collect();
        }

        $key = 'similar:'.Embeddings::generation().':'.md5(json_encode([$this->track->id, $this->hidden, $this->device?->id]));

        return collect(Cache::remember($key, (int) config('similar.cache_seconds'), fn () => $this->search($vector)))->values();
    }

    /** @return list<array{track_id: int, similarity: float, distance: float}> */
    private function search(string $vector): array
    {
        $limit = (int) config('similar.max_results');
        $hits = $this->viaIndex($vector, $limit) ?? VectorScan::top($vector, $this->candidates()
            ->select(['tracks.id as track_id', 'file_embeddings.file_id', 'file_embeddings.vector'])
            ->lazyById(500, 'tracks.id', 'track_id'), $limit);

        return array_map(fn (array $hit) => [
            'track_id' => $hit['track_id'],
            'similarity' => round($hit['similarity'], 4),
            'distance' => round(1 - $hit['similarity'], 4),
        ], $hits);
    }

    /** The index's answer for the scope, or null when it cannot give a complete one (the caller scans). @return list<array{track_id: int, file_id: int, similarity: float}>|null */
    private function viaIndex(string $vector, int $limit): ?array
    {
        $index = $this->index ?? (config('similar.engine') === 'sqlite-vec' ? SqliteVecIndex::configured() : null);
        if ($index === null || !$index->available() || $index->rowCount() !== Embeddings::count()) {
            return null; // not wanted, not loadable, or out of date: the table is the truth
        }

        $k = $limit * max(1, (int) config('similar.vec_overfetch'));

        try {
            $hits = $index->nearest($vector, $k);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $similarity = array_column($hits, 'similarity', 'file_id');
        $tracks = $this->candidates()->whereIn('file_embeddings.file_id', array_keys($similarity))
            ->get(['tracks.id as track_id', 'file_embeddings.file_id']);

        $kept = $tracks->map(fn ($row) => [
            'track_id' => (int) $row->track_id,
            'file_id' => (int) $row->file_id,
            'similarity' => $similarity[$row->file_id],
        ])->sortBy([['similarity', 'desc'], ['track_id', 'asc']])->values();

        // Fewer than wanted while the index had cut its list short: the nearest in scope may lie beyond it.
        if ($kept->count() < $limit && count($hits) >= $k) {
            return null;
        }

        return $kept->take($limit)->all();
    }

    /**
     * The nearest tracks as list items for a shelf (the shape of the Discover hub's `track` sections: `ApiItems::track`
     * plus `similarity`, `distance` and a `reason` sentence naming the seed). Empty when the track has no embedding.
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $limit): array
    {
        $matches = $this->matches()->take($limit)->values();
        $tracks = Track::query()->with(['artist', 'album'])->findMany($matches->pluck('track_id'))->keyBy('id');
        LibraryItemArtwork::preload(LibraryItemArtwork::urlsOfAlbums($tracks->map->album->filter()));

        $items = [];
        foreach ($matches as $match) {
            if ($track = $tracks->get($match['track_id'])) {
                $items[] = ApiItems::track($track) + [
                    'similarity' => $match['similarity'],
                    'distance' => $match['distance'],
                    'reason' => 'Sounds like '.$this->track->name.($this->track->artist ? ' by '.$this->track->artist->name : ''),
                ];
            }
        }

        return $items;
    }

    /**
     * The queue of a "similar tracks radio": the seed first, then the nearest neighbours in order, `similar.radio_length`
     * tracks at most. Empty when the track has no embedding. Both the REST endpoint and the web button play this.
     *
     * @return Collection<int, Track>
     */
    public function radio(?int $length = null): Collection
    {
        $ids = $this->matches()->take(max(1, ($length ?? (int) config('similar.radio_length')) - 1))->pluck('track_id');
        if ($ids->isEmpty()) {
            return collect();
        }

        $found = Track::query()->with(['metadata', 'artist'])->findMany($ids)->keyBy('id');

        return collect([$this->track->loadMissing(['metadata', 'artist'])])
            ->concat($ids->map(fn ($id) => $found[$id] ?? null)->filter())->values();
    }

    /**
     * Why each of these tracks is near this one, by track id: the moods and genres both have, and how far apart the
     * tempos are (a fraction of this track's), from the stored analysis of the primary files.
     *
     * @param  Collection<int, int>|list<int>  $trackIds
     * @return array<int, array{moods: list<string>, genres: list<string>, tempo_diff_percent: float|null}>
     */
    public function reasons(Collection|array $trackIds): array
    {
        $ids = collect($trackIds)->all();
        $rows = FileAnalysis::query()
            ->join('track_files', fn ($j) => $j->on('track_files.file_id', '=', 'file_analyses.file_id')->where('track_files.is_primary', true))
            ->whereIn('track_files.track_id', [...$ids, $this->track->id])
            ->get(['track_files.track_id as owner', 'file_analyses.bpm', 'file_analyses.mood_tags', 'file_analyses.genre_tags'])
            ->keyBy('owner');

        $names = fn ($row, string $column) => collect($row?->{$column} ?? [])->pluck('name')->map(fn ($n) => (string) $n)->all();
        $seed = $rows[$this->track->id] ?? null;
        $seedMoods = $names($seed, 'mood_tags');
        $seedGenres = $names($seed, 'genre_tags');

        $reasons = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $reasons[$id] = [
                'moods' => array_values(array_intersect($seedMoods, $names($row, 'mood_tags'))),
                'genres' => array_values(array_intersect($seedGenres, $names($row, 'genre_tags'))),
                'tempo_diff_percent' => $seed?->bpm > 0 && $row?->bpm > 0 ? round(abs($row->bpm - $seed->bpm) / $seed->bpm * 100, 1) : null,
            ];
        }

        return $reasons;
    }
}
