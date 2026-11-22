<?php

namespace App\Domain\Library;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Similar\Embeddings;
use App\Models\Device;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A journey (workstream 4a, docs/api/journey.md): a queue that moves from one track to another through the sound space, each
 * step a little closer to the end and a little further from the start. The two embeddings are unit vectors; step i of n has the
 * target `(1 - a) * A + a * B` with `a = i / (n - 1)`. The cosine of a candidate with that target ranks like
 * `(1 - a) * dot(c, A) + a * dot(c, B)` (the target's length is the same for every candidate), so one pass over the candidates
 * with two dot products each is enough, however long the journey. Every step takes the best unused candidate, preferring another
 * artist than the step before. The result is the start, the steps and the end, played once.
 *
 * Own analysed files with an embedding only (Full profile), like `SimilarTracks`. Not a classifier: how smooth it feels depends on
 * the library, and a small one gives a bumpy road.
 */
final class Journey
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 30;

    /** @param  list<string>  $hidden  sources hidden by scope and client */
    public function __construct(
        private readonly Track $from,
        private readonly Track $to,
        private readonly array $hidden = [],
        private readonly ?Device $device = null,
    ) {}

    public static function defaultLength(): int
    {
        return max(self::MIN_LENGTH, min(self::MAX_LENGTH, (int) config('similar.journey_length', 12)));
    }

    /** Which end has no embedding yet: `from`, `to`, or null when both are ready. */
    public function missing(): ?string
    {
        return match (true) {
            Embeddings::ofTrack($this->from->id) === null => 'from',
            Embeddings::ofTrack($this->to->id) === null => 'to',
            default => null,
        };
    }

    /**
     * The steps in order as `[track_id, progress]` (progress 0 at the start to 1 at the end, the two ends included). Empty when
     * an end has no embedding or the library has too few candidates to fill the road (at least `$length - 2`).
     *
     * @return list<array{track_id: int, progress: float}>
     */
    public function steps(?int $length = null): array
    {
        $length = max(self::MIN_LENGTH, min(self::MAX_LENGTH, $length ?? self::defaultLength()));
        $key = 'journey:'.Embeddings::generation().':'.md5(json_encode([$this->from->id, $this->to->id, $length, $this->hidden, $this->device?->id]));

        return Cache::remember($key, (int) config('similar.cache_seconds'), fn () => $this->walk($length));
    }

    /** @return list<array{track_id: int, progress: float}> */
    private function walk(int $length): array
    {
        $a = Embeddings::ofTrack($this->from->id);
        $b = Embeddings::ofTrack($this->to->id);
        if ($a === null || $b === null || $this->from->id === $this->to->id) {
            return [];
        }

        $x = unpack('g*', $a);
        $y = unpack('g*', $b);
        $n = count($x);
        $candidates = [];

        foreach ($this->candidates()->select(['tracks.id as track_id', 'tracks.artist_id', 'file_embeddings.vector'])->lazyById(500, 'tracks.id', 'track_id') as $row) {
            $v = unpack('g*', is_resource($row->vector) ? stream_get_contents($row->vector) : $row->vector);
            if (count($v) !== $n) {
                continue;
            }

            $da = 0.0;
            $db = 0.0;
            for ($i = 1; $i <= $n; $i++) {
                $da += $x[$i] * $v[$i];
                $db += $y[$i] * $v[$i];
            }
            $candidates[(int) $row->track_id] = [$da, $db, $row->artist_id];
        }

        $steps = $length - 2;
        if (count($candidates) < $steps) {
            return [];
        }

        $path = [['track_id' => $this->from->id, 'progress' => 0.0]];
        $previousArtist = $this->from->artist_id;
        for ($step = 1; $step <= $steps; $step++) {
            $progress = $step / ($length - 1);
            $best = null;
            $bestScore = -INF;
            $bestOther = null;
            $bestOtherScore = -INF;

            foreach ($candidates as $id => [$da, $db, $artist]) {
                $score = (1 - $progress) * $da + $progress * $db;
                if ($score > $bestScore) {
                    $best = $id;
                    $bestScore = $score;
                }
                if ($score > $bestOtherScore && ($artist === null || $artist !== $previousArtist)) {
                    $bestOther = $id;
                    $bestOtherScore = $score;
                }
            }

            $pick = $bestOther ?? $best;
            $previousArtist = $candidates[$pick][2];
            unset($candidates[$pick]);
            $path[] = ['track_id' => $pick, 'progress' => round($progress, 3)];
        }

        $path[] = ['track_id' => $this->to->id, 'progress' => 1.0];

        return $path;
    }

    /** @return Builder<Track> */
    private function candidates(): Builder
    {
        $query = LibrarySources::tracks(Track::query(), $this->hidden)
            ->join('track_files', fn ($j) => $j->on('track_files.track_id', '=', 'tracks.id')->where('track_files.is_primary', true))
            ->join('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
            ->join('file_embeddings', 'file_embeddings.file_id', '=', 'track_files.file_id')
            ->where('file_embeddings.model', Embeddings::MODEL)
            ->where(fn ($q) => $q->whereNull('file_embeddings.content_hash')->orWhereColumn('file_embeddings.content_hash', 'file_analyses.content_hash'))
            ->whereNotIn('tracks.id', [$this->from->id, $this->to->id]);

        if ($this->device !== null) {
            LibraryPlayback::playableTracks($query, $this->device);
        }

        return $query;
    }

    /**
     * The journey as API items: the track plus `step`, `of`, `progress` and a `reason` ("Step 3 of 12 from A to B").
     *
     * @param  list<array{track_id: int, progress: float}>  $steps
     * @return list<array<string, mixed>>
     */
    public function items(array $steps): array
    {
        $tracks = Track::query()->with(['artist', 'album'])->findMany(array_column($steps, 'track_id'))->keyBy('id');
        LibraryItemArtwork::preload(LibraryItemArtwork::urlsOfAlbums($tracks->map->album->filter()));

        $total = count($steps);
        $items = [];
        foreach ($steps as $i => $step) {
            if ($track = $tracks->get($step['track_id'])) {
                $items[] = ApiItems::track($track) + [
                    'step' => $i + 1,
                    'of' => $total,
                    'progress' => $step['progress'],
                    'reason' => match (true) {
                        $i === 0 => 'The start of the journey',
                        $i === $total - 1 => 'The end of the journey',
                        default => 'Step '.($i + 1).' of '.$total.' from '.$this->from->name.' to '.$this->to->name,
                    },
                ];
            }
        }

        return $items;
    }

    /** The tracks in play order, with what the player needs. @param list<array{track_id: int}> $steps @return Collection<int, Track> */
    public static function tracks(array $steps): Collection
    {
        $tracks = Track::query()->with(['metadata', 'artist'])->findMany(array_column($steps, 'track_id'))->keyBy('id');

        return collect($steps)->map(fn (array $step) => $tracks->get($step['track_id']))->filter()->values();
    }
}
