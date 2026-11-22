<?php

namespace App\Domain\Similar;

use App\Models\File;
use App\Models\SectionEmbedding;

/**
 * The sound embeddings of the sections of own files (workstream 16.5, docs/architecture/section-similarity.md): one
 * Discogs-EffNet vector per section, the mean over the network's patches inside it, in `section_embeddings`. Packed like
 * `Embeddings` (L2-normalised float32, so cosine similarity is the dot product) and tied to the file content they were
 * made from: rows of a changed file are ignored until it is analysed again.
 */
final class SectionEmbeddings
{
    /**
     * Replaces all rows of a file with these sections. Every vector must be usable; one bad vector refuses the lot, so a
     * track never has half its sections.
     *
     * @param  list<array{start: float, end: float, label: ?string, embedding: mixed}>  $sections
     * @return bool false (nothing changed) when a vector is unusable or the list is empty
     */
    public static function store(File $file, ?string $contentHash, array $sections, string $model = Embeddings::MODEL): bool
    {
        $rows = [];
        foreach (array_values($sections) as $position => $section) {
            $packed = Embeddings::pack($section['embedding'] ?? null);
            if ($packed === null) {
                return false;
            }
            $rows[] = [
                'file_id' => $file->id, 'position' => $position,
                'start' => (float) $section['start'], 'end' => (float) $section['end'], 'label' => $section['label'] ?? null,
                'model' => $model, 'vector' => $packed, 'content_hash' => $contentHash,
            ];
        }

        if ($rows === []) {
            return false;
        }

        SectionEmbedding::query()->where('file_id', $file->id)->delete();
        SectionEmbedding::query()->insert($rows);

        return true;
    }

    public static function delete(File $file): void
    {
        SectionEmbedding::query()->where('file_id', $file->id)->delete();
    }
}
