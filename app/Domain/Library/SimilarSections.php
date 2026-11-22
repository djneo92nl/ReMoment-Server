<?php

namespace App\Domain\Library;

use App\Domain\Similar\Embeddings;
use App\Models\Media\Track;
use App\Models\SectionEmbedding;
use App\Models\TrackFile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sections of other tracks that sound like one section of this track (workstream 16.5,
 * docs/architecture/section-similarity.md): the cosine similarity of the section's Discogs-EffNet vector
 * (`section_embeddings`) to every other stored section vector, the best section of each other track, most similar first.
 *
 * Only tracks whose section sound was asked for (deep-analysis kind `section_sound`, one track at a time) have vectors,
 * so the pool is exactly those tracks: a small table, scanned exactly in PHP (about 50 µs per section). A candidate is
 * never the track itself nor the same song by the same artist on another release, and the scope and the client's hidden
 * libraries narrow the pool as for `SimilarTracks`.
 */
final class SimilarSections
{
    /** @param  list<string>  $hidden  sources hidden by scope and client (LibrarySources::hiddenForRequest) */
    public function __construct(private readonly Track $track, private readonly array $hidden = []) {}

    /** The sections of this track that have a current sound vector, in order. @return list<array{index: int, start: float, end: float, label: ?string}> */
    public function sections(): array
    {
        return $this->seedRows()->orderBy('section_embeddings.position')
            ->get(['section_embeddings.position', 'section_embeddings.start', 'section_embeddings.end', 'section_embeddings.label'])
            ->map(fn ($r) => ['index' => (int) $r->position, 'start' => (float) $r->start, 'end' => (float) $r->end, 'label' => $r->label])
            ->all();
    }

    /**
     * @return list<array{track_id: int, section: array{index: int, start: float, end: float, label: ?string}, similarity: float}>|null
     *                                                                                                                                  null when the track has no vector for that section (index out of range or sound not analysed)
     */
    public function matches(int $index, int $limit): ?array
    {
        $seed = $this->seedRows()->where('section_embeddings.position', $index)->first(['section_embeddings.vector']);
        if ($seed === null) {
            return null;
        }

        $q = unpack('g*', self::bytes($seed->vector));
        $best = [];

        foreach ($this->candidates()->lazyById(500, 'section_embeddings.id', 'sid') as $row) {
            $v = unpack('g*', self::bytes($row->vector));
            if (count($v) !== count($q)) {
                continue;
            }

            $score = 0.0;
            foreach ($q as $i => $value) {
                $score += $value * $v[$i];
            }

            $trackId = (int) $row->track_id;
            if (!isset($best[$trackId]) || $score > $best[$trackId]['similarity']) {
                $best[$trackId] = [
                    'track_id' => $trackId,
                    'section' => ['index' => (int) $row->position, 'start' => (float) $row->start, 'end' => (float) $row->end, 'label' => $row->label],
                    'similarity' => $score,
                ];
            }
        }

        usort($best, fn ($a, $b) => [$b['similarity'], $a['track_id']] <=> [$a['similarity'], $b['track_id']]);

        return array_map(fn ($m) => ['similarity' => round($m['similarity'], 4)] + $m, array_slice($best, 0, max(1, $limit)));
    }

    /** The vector rows of this track's primary own file made from its current content. @return Builder<SectionEmbedding> */
    private function seedRows(): Builder
    {
        return SectionEmbedding::query()
            ->join('track_files', fn ($j) => $j->on('track_files.file_id', '=', 'section_embeddings.file_id')
                ->where('track_files.track_id', $this->track->id)->where('track_files.is_primary', true)->where('track_files.role', TrackFile::ORIGINAL))
            ->join('files', 'files.id', '=', 'section_embeddings.file_id')
            ->where('section_embeddings.model', Embeddings::MODEL)
            ->whereColumn('section_embeddings.content_hash', 'files.hash');
    }

    /** The section rows of every other track in scope, with the track id. @return Builder<Track> */
    private function candidates(): Builder
    {
        return LibrarySources::tracks(Track::query(), $this->hidden)
            ->join('track_files', fn ($j) => $j->on('track_files.track_id', '=', 'tracks.id')->where('track_files.is_primary', true)->where('track_files.role', TrackFile::ORIGINAL))
            ->join('files', 'files.id', '=', 'track_files.file_id')
            ->join('section_embeddings', 'section_embeddings.file_id', '=', 'files.id')
            ->where('section_embeddings.model', Embeddings::MODEL)
            ->whereColumn('section_embeddings.content_hash', 'files.hash')
            ->where('tracks.id', '!=', $this->track->id)
            ->when($this->track->artist_id !== null && $this->track->name_key !== null, fn (Builder $q) => $q->whereNot(
                fn ($same) => $same->where('tracks.artist_id', $this->track->artist_id)->where('tracks.name_key', $this->track->name_key)
            ))
            ->select(['tracks.id as track_id', 'section_embeddings.id as sid', 'section_embeddings.position', 'section_embeddings.start', 'section_embeddings.end', 'section_embeddings.label', 'section_embeddings.vector']);
    }

    private static function bytes(mixed $vector): string
    {
        return is_resource($vector) ? (string) stream_get_contents($vector) : (string) $vector;
    }
}
