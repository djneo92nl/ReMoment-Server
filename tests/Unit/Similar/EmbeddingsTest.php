<?php

namespace Tests\Unit\Similar;

use App\Domain\Similar\Embeddings;
use App\Domain\Similar\VectorScan;
use PHPUnit\Framework\TestCase;

/** Packing and normalising sidecar vectors, and the exact scan that ranks them (docs/architecture/similar-tracks.md). */
class EmbeddingsTest extends TestCase
{
    private function vec(float ...$head): array
    {
        return array_pad($head, Embeddings::DIMENSIONS, 0.0);
    }

    public function test_a_vector_is_normalised_and_packed_as_float32(): void
    {
        $packed = Embeddings::pack($this->vec(3.0, 4.0));

        $this->assertSame(Embeddings::DIMENSIONS * 4, strlen($packed));
        $values = Embeddings::unpack($packed);
        $this->assertEqualsWithDelta(0.6, $values[0], 1e-6);
        $this->assertEqualsWithDelta(0.8, $values[1], 1e-6);
        $this->assertEqualsWithDelta(1.0, Embeddings::dot($packed, $packed), 1e-6);
    }

    public function test_unusable_vectors_are_refused(): void
    {
        $this->assertNull(Embeddings::pack(null));
        $this->assertNull(Embeddings::pack('nope'));
        $this->assertNull(Embeddings::pack([1.0, 2.0]), 'wrong length');
        $this->assertNull(Embeddings::pack($this->vec(0.0)), 'all zero');
        $this->assertNull(Embeddings::pack($this->vec(NAN)), 'not finite');
        $this->assertNull(Embeddings::pack($this->vec(INF)), 'not finite');
        $this->assertNull(Embeddings::pack(array_merge($this->vec(1.0), ['x'])), 'wrong length');
        $this->assertNull(Embeddings::pack(array_map(fn () => 'a', range(1, Embeddings::DIMENSIONS))), 'not numbers');
    }

    public function test_the_scan_keeps_the_best_k_ties_by_track_id_and_skips_other_sizes(): void
    {
        $query = Embeddings::pack($this->vec(1.0, 0.0));
        $row = fn (int $id, float ...$head) => (object) ['track_id' => $id, 'file_id' => $id + 100, 'vector' => Embeddings::pack($this->vec(...$head))];

        $top = VectorScan::top($query, [
            $row(5, 0.0, 1.0),   // 0
            $row(4, 1.0, 1.0),   // 0.707
            $row(3, 1.0, 0.0),   // 1
            $row(2, 1.0, 1.0),   // 0.707 (tie with 4: the lower id stays)
            $row(1, -1.0, 0.0),  // -1
            (object) ['track_id' => 9, 'file_id' => 9, 'vector' => pack('g*', 1.0, 0.0)], // another model's size
        ], 3);

        $this->assertSame([3, 2, 4], array_column($top, 'track_id'));
        $this->assertSame(103, $top[0]['file_id']);
        $this->assertEqualsWithDelta(1.0, $top[0]['similarity'], 1e-6);
    }

    public function test_the_scan_of_nothing_is_empty(): void
    {
        $this->assertSame([], VectorScan::top(Embeddings::pack($this->vec(1.0)), [], 5));
    }
}
