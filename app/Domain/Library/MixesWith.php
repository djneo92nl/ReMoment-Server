<?php

namespace App\Domain\Library;

use App\Domain\Analysis\Camelot;
use App\Models\Device;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Mixes well with this" (roadmap 12.2): from one track, the other tracks that follow it well, a query over the
 * existing audio analysis (`file_analyses` of each track's primary file): no new analysis, no table.
 *
 * A candidate qualifies when
 *  - its tempo is within TEMPO_TOLERANCE (6 %) of the track's, or of half or double of it (half/double time),
 *  - its Camelot code is the same, one step either way on the wheel, or the relative key (Camelot::compatible()),
 *  - it is not the same recording: not the track itself, not the same AcoustID recording, and not a track of the
 *    same artist with the same normalised title (a duplicate of another album or source).
 *
 * Sorted by closeness: key relation first (same key, then relative key, then a step on the wheel), then the
 * tempo difference (relative, against the nearest of the track's tempo, half and double), then a direct tempo
 * before half/double time, then the track id so paging is stable.
 */
final class MixesWith
{
    public const TEMPO_TOLERANCE = 0.06;

    /** Key relation => sort rank, smaller first. */
    private const KEY_RANK = ['same' => 0, 'relative' => 1, 'adjacent' => 2];

    /** @param  list<string>  $hidden  sources hidden by scope and client (LibrarySources::hiddenForRequest) */
    public function __construct(private readonly Track $track, private readonly array $hidden = [], private readonly ?Device $device = null) {}

    /** The track's tempo and Camelot code, null when its primary file has no tempo or no key yet. @return array{bpm: float, camelot: string}|null */
    public function source(): ?array
    {
        $analysis = $this->track->analysis()->first();
        $camelot = Camelot::code($analysis?->key, $analysis?->mode);

        return $analysis?->bpm > 0 && $camelot !== null ? ['bpm' => (float) $analysis->bpm, 'camelot' => $camelot] : null;
    }

    /**
     * The matches in order, each `[track_id, bpm, camelot, key_relation, tempo_relation, tempo_diff]` (`tempo_diff`
     * is a fraction, 0.021 = 2.1 %). Empty when the track is not analysed.
     *
     * @return Collection<int, array{track_id: int, bpm: float, camelot: string, key_relation: string, tempo_relation: string, tempo_diff: float}>
     */
    public function matches(): Collection
    {
        $source = $this->source();
        if ($source === null) {
            return collect();
        }

        $targets = ['same' => $source['bpm'], 'half' => $source['bpm'] / 2, 'double' => $source['bpm'] * 2];
        $codes = Camelot::compatible($source['camelot']);

        $query = LibrarySources::tracks(Track::query(), $this->hidden)
            ->join('track_files', fn ($j) => $j->on('track_files.track_id', '=', 'tracks.id')->where('track_files.is_primary', true))
            ->join('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
            ->where('tracks.id', '!=', $this->track->id)
            ->where('file_analyses.bpm', '>', 0)
            ->where(function (Builder $q) use ($targets) {
                foreach ($targets as $bpm) {
                    $q->orWhereBetween('file_analyses.bpm', [$bpm * (1 - self::TEMPO_TOLERANCE), $bpm * (1 + self::TEMPO_TOLERANCE)]);
                }
            })
            ->where(function (Builder $q) use ($codes) {
                foreach ($codes as $code) {
                    $parsed = Camelot::parse($code);
                    $q->orWhere(fn ($k) => $k->where('file_analyses.key', $parsed['key'])->where('file_analyses.mode', $parsed['mode']));
                }
            })
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

        return $query->get(['tracks.id as track_id', 'file_analyses.bpm', 'file_analyses.key', 'file_analyses.mode'])
            ->map(fn ($row) => $this->describe($row, $source, $targets))
            ->filter()
            ->unique('track_id')
            ->sortBy([
                fn ($a, $b) => self::KEY_RANK[$a['key_relation']] <=> self::KEY_RANK[$b['key_relation']],
                fn ($a, $b) => $a['tempo_diff'] <=> $b['tempo_diff'],
                fn ($a, $b) => ($a['tempo_relation'] !== 'same') <=> ($b['tempo_relation'] !== 'same'),
                fn ($a, $b) => $a['track_id'] <=> $b['track_id'],
            ])
            ->values();
    }

    /** @return array{track_id: int, bpm: float, camelot: string, key_relation: string, tempo_relation: string, tempo_diff: float}|null */
    private function describe(object $row, array $source, array $targets): ?array
    {
        $camelot = Camelot::code($row->key, $row->mode);
        if ($camelot === null) {
            return null;
        }

        $relation = $this->keyRelation($source['camelot'], $camelot);
        $tempo = null;
        foreach ($targets as $name => $bpm) {
            $diff = abs($row->bpm - $bpm) / $bpm;
            if ($diff <= self::TEMPO_TOLERANCE && ($tempo === null || $diff < $tempo[1])) {
                $tempo = [$name, $diff];
            }
        }

        return $relation === null || $tempo === null ? null : [
            'track_id' => (int) $row->track_id,
            'bpm' => round((float) $row->bpm, 1),
            'camelot' => $camelot,
            'key_relation' => $relation,
            'tempo_relation' => $tempo[0],
            'tempo_diff' => round($tempo[1], 4),
        ];
    }

    /** same | relative (same number, other letter) | adjacent (one step on the wheel), null when none. */
    private function keyRelation(string $from, string $to): ?string
    {
        if ($from === $to) {
            return 'same';
        }
        if (!in_array($to, Camelot::compatible($from), true)) {
            return null;
        }

        return substr($from, 0, -1) === substr($to, 0, -1) ? 'relative' : 'adjacent';
    }
}
