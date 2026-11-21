<?php

namespace App\Domain\Similar;

/** A search structure over the stored embeddings that is faster than scanning them all (an optional derived cache). */
interface VectorIndex
{
    /** Whether this install can use it at all (extension present and loadable). */
    public function available(): bool;

    /** Rows in the index, to tell whether it still matches `file_embeddings`. */
    public function rowCount(): int;

    /**
     * The $k nearest files to a packed, normalised vector, best first.
     *
     * @return list<array{file_id: int, similarity: float}>
     */
    public function nearest(string $packed, int $k): array;
}
