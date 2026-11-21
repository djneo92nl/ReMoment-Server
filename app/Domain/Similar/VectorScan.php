<?php

namespace App\Domain\Similar;

use SplMinHeap;

/**
 * The exact search: the dot product of one packed, normalised query vector with every candidate row, keeping the best
 * $k. The no-extension path of the plan (docs/architecture/similar-tracks.md): about 50 µs per candidate on a fast
 * machine, so seconds only for tens of thousands of tracks. Rows stream in (never all in memory at once).
 */
final class VectorScan
{
    /**
     * @param  iterable<object{track_id: int|string, file_id: int|string, vector: string}>  $rows
     * @return list<array{track_id: int, file_id: int, similarity: float}> best first, ties by track id
     */
    public static function top(string $query, iterable $rows, int $k): array
    {
        $q = unpack('g*', $query);
        $n = count($q);
        $heap = new class extends SplMinHeap
        {
            /** Worst first: lowest similarity, and among equals the highest id, so the ones that stay are the lowest ids. */
            protected function compare(mixed $a, mixed $b): int
            {
                return [$b[0], $a[1]] <=> [$a[0], $b[1]];
            }
        };

        foreach ($rows as $row) {
            $v = unpack('g*', is_resource($row->vector) ? stream_get_contents($row->vector) : $row->vector);
            if (count($v) !== $n) {
                continue; // a vector of another size can never be compared
            }

            $score = 0.0;
            for ($i = 1; $i <= $n; $i++) {
                $score += $q[$i] * $v[$i];
            }

            if ($heap->count() >= $k && $score < $heap->top()[0]) {
                continue; // worse than everything kept: the usual case once the list is full
            }

            $heap->insert([$score, (int) $row->track_id, (int) $row->file_id]);
            if ($heap->count() > $k) {
                $heap->extract();
            }
        }

        $out = [];
        foreach ($heap as [$score, $trackId, $fileId]) {
            $out[] = ['track_id' => $trackId, 'file_id' => $fileId, 'similarity' => $score];
        }

        usort($out, fn ($a, $b) => [$b['similarity'], $a['track_id']] <=> [$a['similarity'], $b['track_id']]);

        return $out;
    }
}
